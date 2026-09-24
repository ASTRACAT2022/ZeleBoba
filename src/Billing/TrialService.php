<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class TrialService
{
    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet, private ?array $config = null) {}
    /** Whether the user can start a trial. */
    public function available(string $userId): bool
    {
        $user = $this->db->one('SELECT * FROM users WHERE id=?', [$userId]);
        if (!$user || (int)$user['disabled']===1) return false;
        if ((int)$user['has_had_paid_subscription'] === 1) return false;
        $any = $this->db->one("SELECT id FROM subscriptions WHERE user_id=? AND (is_trial=1 OR status IN ('active','trial','limited','provisioning')) LIMIT 1", [$userId]);
        if ($any) return false;
        return true;
    }
    /** Start a trial for a plan. Returns the subscription row. */
    public function start(string $userId, string $planId): array
    {
        return $this->db->transaction(function () use ($userId, $planId) {
            $this->db->one('SELECT id FROM users WHERE id=?'.$this->db->lock(),[$userId]);
            if (!$this->available($userId)) throw new BillingError('Триал недоступен: у вас уже была подписка.');

            $plan = $this->db->one('SELECT * FROM plans WHERE id=? AND active=1 AND is_trial_available=1' . $this->db->lock(), [$planId]);
            if (!$plan) throw new BillingError('Триал на этом тарифе недоступен.');
            $days = (int)($plan['trial_duration_days'] ?? $this->config['TRIAL_DURATION_DAYS'] ?? 3);
            if ($days < 1) $days = 3;
            $price = (int)($plan['trial_price_kopeks'] ?? 0);
            $now = time();
            if ($price > 0) {
                $this->wallet->debit($userId, $price, 'trial_conversion', 'Активация триальной подписки');
            }
            $sub = Database::id();
            $this->db->execute(
                "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,plan_id,traffic_limit_gb,device_limit,is_trial,start_date,updated_at) VALUES(?,NULL,?,'active',?,?,?,?,?,1,?,?)",
                [$sub, $userId, $now + $days * 86400, $now, $plan['id'], (int)$plan['traffic_bytes'] / 1073741824, (int)$plan['devices'], $now, $now]
            );
            $this->outbox->enqueue('subscription.provision', 'provision:'.$sub, ['subscription_id' => $sub]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $userId, 'trial.started', $sub, $now]);
            return $this->db->one('SELECT * FROM subscriptions WHERE id=?', [$sub]);
        });
    }
    /** Convert a trial subscription to paid in place (same Remnawave user/link). */
    public function convertToPaid(string $userId, string $subscriptionId, string $planId, int $priceKopeks, string $paymentMethod = 'balance'): array
    {
        return $this->db->transaction(function () use ($userId, $subscriptionId, $planId, $priceKopeks, $paymentMethod) {
            $sub = $this->db->one('SELECT * FROM subscriptions WHERE id=?' . $this->db->lock(), [$subscriptionId]);
            if (!$sub || $sub['user_id'] !== $userId) throw new BillingError('Подписка не найдена.');
            if ((int)$sub['is_trial'] !== 1 || !in_array($sub['status'], ['active', 'trial', 'limited'], true)) throw new BillingError('Подписка не является активным триалом.');
            $plan = $this->db->one('SELECT * FROM plans WHERE id=? AND active=1', [$planId]);
            if (!$plan) throw new BillingError('Тариф недоступен.');
            if ($priceKopeks !== (int)$plan['price_minor']) throw new BillingError('Цена тарифа изменилась. Обновите страницу.');
            $this->wallet->debit($userId, $priceKopeks, 'subscription_purchase', 'Покупка подписки: '.$plan['name'], $paymentMethod);
            $now = time();
            $base = max($now, (int)$sub['expires_at']);
            $carry = ($this->config['TRIAL_ADD_REMAINING_DAYS_TO_PAID'] ?? '0') === '1';
            $newExpiry = $carry ? $base + (int)$plan['duration_days'] * 86400 : $now + (int)$plan['duration_days'] * 86400;
            $this->db->execute(
                "UPDATE subscriptions SET status='active',is_trial=0,expires_at=?,plan_id=?,traffic_limit_gb=?,device_limit=?,updated_at=? WHERE id=?",
                [$newExpiry, $plan['id'], (int)$plan['traffic_bytes'] / 1073741824, (int)$plan['devices'], $now, $subscriptionId]
            );
            $this->db->execute('UPDATE users SET has_had_paid_subscription=1 WHERE id=?', [$userId]);
            $conversionId = Database::id();
            $this->db->execute('INSERT INTO subscription_conversions(id,user_id,converted_at,trial_duration_days,payment_method,first_payment_amount_kopeks,first_paid_period_days,created_at) VALUES(?,?,?,?,?,?,?,?)', [$conversionId, $userId, $now, (int)$sub['expires_at'] > 0 ? (int)round(((int)$sub['expires_at'] - (int)$sub['start_date']) / 86400) : null, $paymentMethod, $priceKopeks, (int)$plan['duration_days'], $now]);
            $this->outbox->enqueue('subscription.extend', 'trial-convert:'.$conversionId, ['subscription_id' => $subscriptionId]);
            $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $userId, 'trial.converted', $subscriptionId, $now]);
            return $this->db->one('SELECT * FROM subscriptions WHERE id=?', [$subscriptionId]);
        });
    }
    /** Expire trials whose time is up. Returns count. */
    public function expireOverdue(): int
    {
        $now = time();
        $rows = $this->db->all("SELECT id FROM subscriptions WHERE is_trial=1 AND status IN ('active','trial') AND expires_at<=?", [$now]);
        foreach ($rows as $row) {
            $this->db->execute("UPDATE subscriptions SET status='expired',updated_at=? WHERE id=? AND status IN ('active','trial')", [$now, $row['id']]);
        }
        return count($rows);
    }
}
