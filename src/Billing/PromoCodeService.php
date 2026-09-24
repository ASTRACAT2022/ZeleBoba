<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class PromoCodeService
{
    public const TYPES = ['balance','subscription_days','trial_subscription','discount','balance_and_days'];
    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet) {}
    /** Create a promocode. Returns the row. */
    public function create(array $input, string $actor): array
    {
        $code = mb_strtoupper(trim((string)($input['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9_-]{3,50}$/D', $code)) throw new BillingError('Код: 3–50 символов A-Z, 0-9, _ или -.');
        $type = (string)($input['type'] ?? '');
        if (!in_array($type, self::TYPES, true)) throw new BillingError('Некорректный тип промокода.');
        $balance = (int)($input['balance_bonus_kopeks'] ?? 0);
        $days = (int)($input['subscription_days'] ?? 0);
        $traffic = (int)($input['traffic_gb'] ?? 0);
        $maxUses = (int)($input['max_uses'] ?? 1);
        $validFrom = (int)($input['valid_from'] ?? time());
        $validUntil = $input['valid_until'] !== '' && $input['valid_until'] !== null ? (int)$input['valid_until'] : null;
        $firstOnly = (int)($input['first_purchase_only'] ?? 0);
        $planId = (string)($input['plan_id'] ?? '');
        if ($balance < 0 || $balance > 99000000) throw new BillingError('Бонус: от 0 до 1 000 000 ₽.');
        if ($days < 0 || $days > 3650) throw new BillingError('Дни: от 0 до 3650.');
        if ($traffic < 0 || $traffic > 100000) throw new BillingError('Трафик: от 0 до 100 000 ГБ.');
        if ($maxUses < 1 || $maxUses > 1000000) throw new BillingError('Лимит использований: от 1 до 1 000 000.');
        if ($type === 'discount' && ($balance < 1 || $balance > 99)) throw new BillingError('Для скидки укажите процент 1–99.');
        if ($type === 'trial_subscription' && $days < 1) throw new BillingError('Для триала укажите дни.');
        if ($planId !== '' && !$this->db->one('SELECT id FROM plans WHERE id=?', [$planId])) throw new BillingError('Тариф не найден.');
        return $this->db->transaction(function () use ($code,$type,$balance,$days,$traffic,$maxUses,$validFrom,$validUntil,$firstOnly,$planId,$actor) {
            if ($this->db->one('SELECT id FROM promocodes WHERE code=?', [$code])) throw new BillingError('Такой код уже существует.');
            $id = Database::id();
            $this->db->execute(
                'INSERT INTO promocodes(id,code,type,balance_bonus_kopeks,subscription_days,traffic_gb,max_uses,current_uses,valid_from,valid_until,is_active,first_purchase_only,plan_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,0,?,?,1,?,?,?,?)',
                [$id,$code,$type,$balance,$days,$traffic,$maxUses,$validFrom,$validUntil,$firstOnly,$planId!==''?$planId:null,$actor,time()]
            );
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'promocode.created', $code, time()]);
            return $this->db->one('SELECT * FROM promocodes WHERE id=?', [$id]);
        });
    }
    /** Activate a promocode for a user. Returns ['success'=>bool,'error'=>?string,'description'=>?string]. */
    public function activate(string $userId, string $code): array
    {
        $code = mb_strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9_-]{3,50}$/D', $code)) return ['success' => false, 'error' => 'not_found'];
        return $this->db->transaction(function () use ($userId, $code) {
            $user = $this->db->one('SELECT * FROM users WHERE id=?' . $this->db->lock(), [$userId]);
            if (!$user) return ['success' => false, 'error' => 'user_not_found'];
            $promo = $this->db->one('SELECT * FROM promocodes WHERE code=?' . $this->db->lock(), [$code]);
            if (!$promo) return ['success' => false, 'error' => 'not_found'];
            $now = time();
            if ((int)$promo['is_active'] !== 1) return ['success' => false, 'error' => 'inactive'];
            if ((int)$promo['current_uses'] >= (int)$promo['max_uses']) return ['success' => false, 'error' => 'used'];
            if ((int)$promo['valid_from'] > $now) return ['success' => false, 'error' => 'not_yet_valid'];
            if ($promo['valid_until'] !== null && (int)$promo['valid_until'] < $now) return ['success' => false, 'error' => 'expired'];
            if ($this->db->one('SELECT id FROM promocode_uses WHERE user_id=? AND promocode_id=?', [$userId, $promo['id']])) return ['success' => false, 'error' => 'already_used_by_user'];
            $recent = (int)($this->db->one('SELECT COUNT(*) AS c FROM promocode_uses WHERE user_id=? AND used_at>?', [$userId, $now - 86400])['c'] ?? 0);
            if ($recent >= 5) return ['success' => false, 'error' => 'daily_limit'];
            if ((int)$promo['first_purchase_only'] === 1 && (int)$user['has_had_paid_subscription'] === 1) return ['success' => false, 'error' => 'not_first_purchase'];
            $claimed = $this->db->execute('UPDATE promocodes SET current_uses=current_uses+1 WHERE id=? AND current_uses<max_uses', [$promo['id']]);
            if (!$claimed) return ['success' => false, 'error' => 'used'];
            $this->db->execute('INSERT INTO promocode_uses VALUES(?,?,?,?)', [Database::id(), $promo['id'], $userId, $now]);
            try {
                $description = $this->applyEffects($user, $promo);
            } catch (BillingError $e) {
                $this->db->execute('UPDATE promocodes SET current_uses=current_uses-1 WHERE id=?', [$promo['id']]);
                $this->db->execute('DELETE FROM promocode_uses WHERE user_id=? AND promocode_id=?', [$userId, $promo['id']]);
                return ['success' => false, 'error' => $e->getMessage()];
            }
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $userId, 'promocode.activated', $code, time()]);
            return ['success' => true, 'description' => $description];
        });
    }
    private function applyEffects(array $user, array $promo): string
    {
        $effects = [];
        $type = $promo['type'];
        $now = time();
        if ($type === 'discount') {
            $current = (int)$user['promo_offer_discount_percent'];
            $expires = $user['promo_offer_discount_expires_at'];
            if ($current > 0 && ($expires === null || (int)$expires > $now)) throw new BillingError('active_discount_exists');
            $percent = (int)$promo['balance_bonus_kopeks'];
            $hours = (int)$promo['subscription_days'];
            $this->db->execute('UPDATE users SET promo_offer_discount_percent=?,promo_offer_discount_source=?,promo_offer_discount_expires_at=? WHERE id=?', [$percent, 'promocode:'.$promo['code'], $hours > 0 ? $now + $hours * 3600 : null, $user['id']]);
            $effects[] = $hours > 0 ? '💸 Получена скидка '.$percent.'% (действует '.$hours.' ч.)' : '💸 Получена скидка '.$percent.'% до первой покупки';
        }
        $targetSub = null;
        if (in_array($type, ['subscription_days','balance_and_days'], true) && (int)$promo['subscription_days'] > 0) {
            $targetSub = $this->pickTargetSubscription($user, $promo);
            $this->extendSubscription($targetSub, (int)$promo['subscription_days']);
            $effects[] = '⏰ Подписка продлена на '.(int)$promo['subscription_days'].' дней';
        }
        if ($type === 'balance_and_days' && (int)$promo['traffic_gb'] > 0) {
            if ($targetSub === null) $targetSub = $this->pickTargetSubscription($user, $promo);
            if ((int)$targetSub['traffic_limit_gb'] === 0) throw new BillingError('traffic_not_applicable');
            $this->db->execute('UPDATE subscriptions SET purchased_traffic_gb = purchased_traffic_gb + ? WHERE id=?', [(int)$promo['traffic_gb'], $targetSub['id']]);
            $this->outbox->enqueue('subscription.traffic', 'traffic:'.$targetSub['id'].':'.$promo['id'], ['subscription_id' => $targetSub['id'], 'traffic_gb' => (int)$promo['traffic_gb']]);
            $effects[] = '📦 Трафик пополнен на '.(int)$promo['traffic_gb'].' ГБ';
        }
        if (in_array($type, ['balance','balance_and_days'], true) && (int)$promo['balance_bonus_kopeks'] > 0) {
            $this->wallet->credit($user['id'], (int)$promo['balance_bonus_kopeks'], 'promo_credit', 'Бонус по промокоду '.$promo['code']);
            $effects[] = '💰 Баланс пополнен на '.((int)$promo['balance_bonus_kopeks'] / 100).' ₽';
        }
        if ($type === 'trial_subscription') {
            $days = (int)$promo['subscription_days'];
            $planId = $promo['plan_id'];
            $plan = $planId ? $this->db->one('SELECT * FROM plans WHERE id=? AND active=1', [$planId]) : null;
            if (!$plan) throw new BillingError('trial_subscription_exists');
            $existing = $this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial') ORDER BY created_at DESC LIMIT 1", [$user['id']]);
            if ($existing) {
                $this->extendSubscription($existing, $days);
                $effects[] = '⏰ Подписка продлена на '.$days.' дней';
            } else {
                $sub = Database::id();
                $this->db->execute("INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at) VALUES(?,?,?,'trial',?,?)", [$sub, null, $user['id'], $now + $days * 86400, $now]);
                $this->db->execute('UPDATE subscriptions SET plan_id=?,traffic_limit_gb=?,device_limit=? WHERE id=?', [$plan['id'], (int)$plan['traffic_bytes'] / 1073741824, (int)$plan['devices'], $sub]);
                $this->outbox->enqueue('subscription.provision', 'provision:'.$sub, ['subscription_id' => $sub]);
                $effects[] = '🎁 Активирована тестовая подписка на '.$days.' дней';
            }
        }
        if (in_array($type, ['subscription_days','balance_and_days'], true) && (int)$promo['subscription_days'] > 0) {
            $this->db->execute('UPDATE users SET has_had_paid_subscription=1 WHERE id=?', [$user['id']]);
        }
        return $effects ? implode("\n", $effects) : '✅ Промокод активирован';
    }
    private function pickTargetSubscription(array $user, array $promo): array
    {
        $sub = $this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial') ORDER BY created_at DESC LIMIT 1", [$user['id']]);
        if (!$sub) throw new BillingError('no_subscription_for_days');
        return $sub;
    }
    private function extendSubscription(array $sub, int $days): void
    {
        $base = max(time(), (int)$sub['expires_at']);
        $this->db->execute("UPDATE subscriptions SET expires_at=?,status='active' WHERE id=?", [$base + $days * 86400, $sub['id']]);
        $this->outbox->enqueue('subscription.extend', 'extend:'.$sub['id'].':'.Database::id(), ['subscription_id' => $sub['id']]);
    }
    public function list(int $limit = 100): array
    {
        return $this->db->all('SELECT * FROM promocodes ORDER BY created_at DESC LIMIT ?', [$limit]);
    }
    public function toggle(string $id, bool $active, string $actor): void
    {
        $this->db->execute('UPDATE promocodes SET is_active=? WHERE id=?', [(int)$active, $id]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, $active ? 'promocode.enabled' : 'promocode.disabled', $id, time()]);
    }
}
