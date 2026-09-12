<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;
use App\Integration\Provisioner;
final class UserAdminService
{
    public function __construct(private Database $db, private Wallet $wallet, private ?Provisioner $provisioner = null, private ?\App\Infrastructure\Outbox $outbox = null, private ?CustomerTimeline $timeline = null) {}
    /** Full user profile for the admin: account, balance, subscriptions, orders, promocodes, referrals. */
    public function profile(string $userId): array
    {
        $user = $this->db->one('SELECT * FROM users WHERE id=?', [$userId]);
        if (!$user) throw new BillingError('Пользователь не найден.');
        $subscriptions = $this->db->all(
            "SELECT s.*,COALESCE(o.plan_name,p.name) AS plan_name FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? ORDER BY s.created_at DESC",
            [$userId]
        );
        $orders = $this->db->all('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 50', [$userId]);
        $topups = $this->db->all('SELECT * FROM topups WHERE user_id=? ORDER BY created_at DESC LIMIT 20', [$userId]);
        $transactions = $this->db->all('SELECT * FROM transactions WHERE user_id=? ORDER BY seq DESC LIMIT 30', [$userId]);
        $promoUses = $this->db->all(
            'SELECT pu.used_at,p.code,p.type,p.balance_bonus_kopeks,p.subscription_days FROM promocode_uses pu JOIN promocodes p ON p.id=pu.promocode_id WHERE pu.user_id=? ORDER BY pu.used_at DESC',
            [$userId]
        );
        $referrals = $this->db->all('SELECT id,email,telegram_id,created_at,has_made_first_topup FROM users WHERE referred_by_id=? ORDER BY created_at DESC', [$userId]);
        $referrer = $user['referred_by_id'] ? $this->db->one('SELECT id,email,telegram_id FROM users WHERE id=?', [$user['referred_by_id']]) : null;
        $spending = $this->db->one("SELECT COALESCE(SUM(amount_kopeks),0) AS s FROM transactions WHERE user_id=? AND amount_kopeks<0 AND type IN ('subscription_purchase','subscription_renewal','gift_purchase','traffic_topup','device_addon')", [$userId]);
        $gifts = $this->db->all('SELECT * FROM guest_purchases WHERE buyer_user_id=? AND is_gift=1 ORDER BY created_at DESC', [$userId]);
        return [
            'user' => $user,
            'subscriptions' => $subscriptions,
            'orders' => $orders,
            'topups' => $topups,
            'transactions' => $transactions,
            'promo_uses' => $promoUses,
            'referrals' => $referrals,
            'referrer' => $referrer,
            'spent_kopeks' => -(int)($spending['s'] ?? 0),
            'gifts' => $gifts,
            'timeline' => $this->timeline?->forUser($userId) ?? [],
        ];
    }
    /** Adjust user balance (credit or debit) with audit. */
    public function adjustBalance(string $userId, int $amountKopeks, string $reason, string $actor): void
    {
        if ($amountKopeks === 0) throw new BillingError('Сумма не может быть нулевой.');
        if (abs($amountKopeks) > 100000000) throw new BillingError('Сумма слишком большая.');
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) throw new BillingError('Причина: 3–200 символов.');
        $this->db->transaction(function () use ($userId, $amountKopeks, $reason, $actor) {
            if ($amountKopeks > 0) {
                $this->wallet->credit($userId, $amountKopeks, 'manual_adjust', 'Ручная корректировка: '.$reason);
            } else {
                $this->wallet->debit($userId, -$amountKopeks, 'manual_adjust', 'Ручная корректировка: '.$reason);
            }
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.balance_adjusted', $userId, time()]);
        });
    }
    /** Grant subscription days to a user (extend or create). */
    public function grantDays(string $userId, int $days, ?string $planId, string $actor): array
    {
        if ($days < 1 || $days > 3650) throw new BillingError('Дни: 1–3650.');
        return $this->db->transaction(function () use ($userId, $days, $planId, $actor) {
            $sub = $this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial','provisioning') ORDER BY created_at DESC LIMIT 1" . $this->db->lock(), [$userId]);
            if ($sub) {
                $base = max(time(), (int)$sub['expires_at']);
                $this->db->execute("UPDATE subscriptions SET expires_at=?,status='active',updated_at=? WHERE id=?", [$base + $days * 86400, time(), $sub['id']]);
                $this->outbox?->enqueue('subscription.extend','admin-extend:'.Database::id(),['subscription_id'=>$sub['id']]);
                $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.days_granted', $userId, time()]);
                return $this->db->one('SELECT * FROM subscriptions WHERE id=?', [$sub['id']]);
            }
            $plan = $planId ? $this->db->one('SELECT * FROM plans WHERE id=?', [$planId]) : null;
            $id = Database::id();
            $now = time();
            $this->db->execute(
                "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,device_limit,is_trial,start_date,updated_at) VALUES(?,NULL,?,'active',?,?,?,?,?,0,?,?)",
                [$id, $userId, $now + $days * 86400, $now, $plan['id'] ?? null, $plan ? (int)$plan['traffic_bytes'] / 1073741824 : 0, $plan ? (int)$plan['devices'] : 1, $now, $now]
            );
            $this->outbox?->enqueue('subscription.provision','provision:'.$id,['subscription_id'=>$id]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.days_granted', $userId, time()]);
            return $this->db->one('SELECT * FROM subscriptions WHERE id=?', [$id]);
        });
    }
    /** Set a promo discount for a user. */
    public function setDiscount(string $userId, int $percent, int $hours, string $actor): void
    {
        if ($percent < 1 || $percent > 99) throw new BillingError('Скидка: 1–99%.');
        if ($hours < 0 || $hours > 8760) throw new BillingError('Часы: 0–8760.');
        $this->db->execute('UPDATE users SET promo_offer_discount_percent=?,promo_offer_discount_source=?,promo_offer_discount_expires_at=? WHERE id=?', [$percent, 'admin:'.$actor, $hours > 0 ? time() + $hours * 3600 : null, $userId]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.discount_set', $userId, time()]);
    }
    public function clearDiscount(string $userId, string $actor): void
    {
        $this->db->execute('UPDATE users SET promo_offer_discount_percent=0,promo_offer_discount_source=NULL,promo_offer_discount_expires_at=NULL WHERE id=?', [$userId]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.discount_cleared', $userId, time()]);
    }
    /** Full subscription view with live panel data (traffic used, devices, status). */
    public function subscription(string $subscriptionId): array
    {
        $sub = $this->db->one(
            "SELECT s.*,COALESCE(o.plan_name,p.name) AS plan_name,u.email,u.telegram_id FROM subscriptions s LEFT JOIN orders o ON o.id=s.order_id LEFT JOIN plans p ON p.id=s.plan_id LEFT JOIN users u ON u.id=s.user_id WHERE s.id=?",
            [$subscriptionId]
        );
        if (!$sub) throw new BillingError('Подписка не найдена.');
        $panel = null;
        if ($this->provisioner) {
            try {
                if ((int)($sub['remnawave_id'] ?? 0) > 0) {
                    $panel = $this->provisioner->fetchById((int)$sub['remnawave_id']);
                } else {
                    $panel = $this->provisioner->fetch('zb_'.$sub['id']);
                }
            } catch (\Throwable $e) {}
        }
        $panelTrafficUsed = 0;
        if ($panel) {
            $panelTrafficUsed = (int)($panel['userTraffic']['usedTrafficBytes'] ?? 0) / 1073741824;
        }
        return [
            'sub' => $sub,
            'panel' => $panel,
            'panel_traffic_used_gb' => (float)$panelTrafficUsed,
            'plans' => $this->db->all('SELECT id,name,duration_days,traffic_bytes,devices FROM plans ORDER BY display_order'),
        ];
    }
    /** Update subscription traffic limit (GB) locally + push to Remnawave. */
    public function updateSubscriptionTraffic(string $userId, string $subscriptionId, int $trafficGb, string $actor): void
    {
        if ($trafficGb < 0 || $trafficGb > 100000) throw new BillingError('Трафик 0–100000 ГБ.');
        $this->db->transaction(function () use ($userId, $subscriptionId, $trafficGb, $actor) {
            $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=?' . $this->db->lock(), [$subscriptionId]);
            if (!$sub || $sub['user_id'] !== $userId) throw new BillingError('Подписка не найдена.');
            $this->db->execute('UPDATE subscriptions SET traffic_limit_gb=? WHERE id=?', [$trafficGb, $subscriptionId]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.subscription_traffic_changed', $userId, time()]);
        });
        $this->applyPanelChanges($subscriptionId);
    }
    /** Update subscription device limit locally + push to Remnawave (0 = unlimited). */
    public function updateSubscriptionDevices(string $userId, string $subscriptionId, int $devices, string $actor): void
    {
        if ($devices < 0 || $devices > 20) throw new BillingError('Устройства 0–20 (0 = безлимит).');
        $this->db->transaction(function () use ($userId, $subscriptionId, $devices, $actor) {
            $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=?' . $this->db->lock(), [$subscriptionId]);
            if (!$sub || $sub['user_id'] !== $userId) throw new BillingError('Подписка не найдена.');
            $this->db->execute('UPDATE subscriptions SET device_limit=? WHERE id=?', [$devices, $subscriptionId]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.subscription_devices_changed', $userId, time()]);
        });
        $this->applyPanelChanges($subscriptionId);
    }
    /** Extend subscription by N days (from now or from current expiry), sync panel expiry. */
    public function extendSubscription(string $userId, string $subscriptionId, int $days, string $actor): void
    {
        if ($days < 1 || $days > 3650) throw new BillingError('Дни: 1–3650.');
        $this->db->transaction(function () use ($userId, $subscriptionId, $days, $actor) {
            $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=?' . $this->db->lock(), [$subscriptionId]);
            if (!$sub || $sub['user_id'] !== $userId) throw new BillingError('Подписка не найдена.');
            $base = max(time(), (int)$sub['expires_at']);
            $this->db->execute("UPDATE subscriptions SET expires_at=?,status='active',updated_at=? WHERE id=?", [$base + $days * 86400, time(), $subscriptionId]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.subscription_extended', $userId, time()]);
        });
        $this->applyPanelChanges($subscriptionId);
    }
    /** Set an exact expiration datetime (UTC timestamp) for one subscription. */
    public function setSubscriptionExpiry(string $userId, string $subscriptionId, int $expiresAt, string $actor): void
    {
        $min = time() - 3600; // allow slightly-past; anything older is a mistake
        if ($expiresAt < $min) throw new BillingError('Дата окончания не может быть в прошлом.');
        if ($expiresAt > time() + 3650 * 86400) throw new BillingError('Дата окончания слишком далеко (макс. 10 лет).');
        $this->db->transaction(function () use ($userId, $subscriptionId, $expiresAt, $actor) {
            $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=?' . $this->db->lock(), [$subscriptionId]);
            if (!$sub || $sub['user_id'] !== $userId) throw new BillingError('Подписка не найдена.');
            $this->db->execute("UPDATE subscriptions SET expires_at=?,status='active',updated_at=? WHERE id=?", [$expiresAt, time(), $subscriptionId]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.subscription_expiry_set', $userId, time()]);
        });
        $this->applyPanelChanges($subscriptionId);
    }
    /** Reset used traffic on panel (bytesUsed back to 0). */
    public function resetSubscriptionTraffic(string $userId, string $subscriptionId, string $actor): void
    {
        $this->db->transaction(function () use ($userId, $subscriptionId, $actor) {
            $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=?' . $this->db->lock(), [$subscriptionId]);
            if (!$sub || $sub['user_id'] !== $userId) throw new BillingError('Подписка не найдена.');
            $this->db->execute('UPDATE subscriptions SET traffic_used_gb=0,updated_at=? WHERE id=?', [time(), $subscriptionId]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.subscription_traffic_reset', $userId, time()]);
        });
        $this->applyPanelChanges($subscriptionId);
    }
    /** Push current local values (traffic/devices/expiry) to Remnawave panel. */
    private function applyPanelChanges(string $subscriptionId): void
    {
        if (!$this->provisioner) return;
        $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=?', [$subscriptionId]);
        if (!$sub || $sub['status']!=='active') return;
        $sub['traffic_bytes'] = (int)$sub['traffic_limit_gb'] * 1073741824;
        $sub['devices'] = (int)$sub['device_limit'];
        try {
            $panel = null;
            if ((int)($sub['remnawave_id'] ?? 0) > 0) {
                $panel = $this->provisioner->fetchById((int)$sub['remnawave_id']);
                if ($panel && ($panel['id'] ?? null)) {
                    $this->provisioner->updateById((int)$panel['id'], (int)$sub['traffic_bytes'], (int)$sub['devices'], (int)$sub['expires_at']);
                    return;
                }
            }
            $panel = $this->provisioner->fetch('zb_'.$sub['id']);
            if ($panel && ($panel['id'] ?? null)) {
                $this->provisioner->extend($sub); // PATCHes traffic + devices + expireAt by username
            }
        } catch (\Throwable $e) {}
    }
    /** Remove a subscription: deletes the panel user (Remnawave) and the local row. */
    public function removeSubscription(string $userId, string $subscriptionId, string $actor): void
    {
        $this->db->transaction(function () use ($userId, $subscriptionId, $actor) {
            $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=?' . $this->db->lock(), [$subscriptionId]);
            if (!$sub || $sub['user_id'] !== $userId) throw new BillingError('Подписка не найдена.');
            // 1) Delete the VPN user from Remnawave (legacy subs use panel id, new ones use zb_<id>).
            if ($this->provisioner) {
                $panelId = (int)($sub['remnawave_id'] ?? 0);
                if ($panelId > 0) {
                    if (method_exists($this->provisioner, 'removeById')) {
                        $this->provisioner->removeById($panelId);
                    } else {
                        $this->provisioner->remove('zb_' . $sub['id']);
                    }
                } else {
                    $this->provisioner->remove('zb_' . $sub['id']);
                }
            }
            // 2) Drop pending ops for this subscription.
            $this->db->execute("DELETE FROM outbox WHERE topic IN ('subscription.provision','subscription.extend') AND payload LIKE ?", ['%'.$subscriptionId.'%']);
            // 3) Delete local row (no FK constraints on subscriptions).
            $this->db->execute('DELETE FROM subscriptions WHERE id=?', [$subscriptionId]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'user.subscription_removed', $userId, time()]);
        });
    }
    /** Search users by email, telegram id, referral code. */
    public function search(string $query, int $limit = 50): array
    {
        $q = trim($query);
        if ($q === '') return [];
        $like = '%'.$q.'%';
        return $this->db->all(
            'SELECT id,email,telegram_id,role,disabled,balance_kopeks,created_at FROM users WHERE email LIKE ? OR telegram_id LIKE ? OR referral_code LIKE ? ORDER BY created_at DESC LIMIT ?',
            [$like, $like, $like, $limit]
        );
    }
}
