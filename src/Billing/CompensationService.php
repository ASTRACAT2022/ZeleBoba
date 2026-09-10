<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class CompensationService
{
    public const SEGMENTS = ['all','active','inactive','paid','trial','telegram'];
    public const KINDS = ['balance','days','traffic'];
    public function __construct(private Database $db, private Outbox $outbox, private Wallet $wallet) {}
    /** Create a mass compensation job. Returns the row. */
    public function create(string $segment, string $kind, int $value, string $reason, string $adminId, string $adminName): array
    {
        if (!in_array($segment, self::SEGMENTS, true)) throw new BillingError('Некорректный сегмент.');
        if (!in_array($kind, self::KINDS, true)) throw new BillingError('Некорректный тип компенсации.');
        if ($value < 1 || $value > 100000000) throw new BillingError('Значение: от 1 до 100 000 000.');
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) throw new BillingError('Причина: 3–200 символов.');
        $id = Database::id();
        $now = time();
        $this->db->execute(
            'INSERT INTO compensations(id,segment,kind,value,reason,total_count,processed_count,status,admin_id,admin_name,created_at) VALUES(?,?,?,?,?,0,0,?,?,?,?)',
            [$id, $segment, $kind, $value, $reason, 'in_progress', $adminId, $adminName, $now]
        );
        $this->outbox->enqueue('compensation.run', 'compensation:'.$id, ['compensation_id' => $id]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $adminId, 'compensation.created', $id, $now]);
        return $this->db->one('SELECT * FROM compensations WHERE id=?', [$id]);
    }
    /** Run a compensation: pick recipients and enqueue per-user grants. */
    public function run(string $compensationId): void
    {
        $c = $this->db->one('SELECT * FROM compensations WHERE id=?', [$compensationId]);
        if (!$c || $c['status'] !== 'in_progress') return;
        $recipients = $this->recipients($c['segment']);
        $this->db->execute('UPDATE compensations SET total_count=? WHERE id=?', [count($recipients), $compensationId]);
        foreach ($recipients as $userId) {
            $this->outbox->enqueue('compensation.grant', 'cgrant:'.$compensationId.':'.$userId, [
                'compensation_id' => $compensationId, 'user_id' => $userId,
            ]);
        }
        $this->db->execute("UPDATE compensations SET status='running' WHERE id=?", [$compensationId]);
    }
    /** Grant compensation to a single user. Idempotent per (compensation, user). */
    public function grant(string $compensationId, string $userId): void
    {
        $c = $this->db->one('SELECT * FROM compensations WHERE id=?', [$compensationId]);
        if (!$c) return;
        $this->db->transaction(function () use ($c, $compensationId, $userId) {
            if ($this->db->one('SELECT id FROM compensation_grants WHERE compensation_id=? AND user_id=?', [$compensationId, $userId])) return;
            $this->db->execute('INSERT INTO compensation_grants(id,compensation_id,user_id,created_at) VALUES(?,?,?,?)', [Database::id(), $compensationId, $userId, time()]);
            $kind = $c['kind'];
            $value = (int)$c['value'];
            $reason = 'Компенсация: '.$c['reason'];
            if ($kind === 'balance') {
                $this->wallet->credit($userId, $value, 'manual_adjust', $reason);
            } elseif ($kind === 'days') {
                $this->grantDays($userId, $value, $reason);
            } elseif ($kind === 'traffic') {
                $this->grantTraffic($userId, $value, $reason);
            }
            $this->db->execute('UPDATE compensations SET processed_count=processed_count+1 WHERE id=?', [$compensationId]);
        });
    }
    private function grantDays(string $userId, int $days, string $reason): void
    {
        $sub = $this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial','provisioning') ORDER BY created_at DESC LIMIT 1" . $this->db->lock(), [$userId]);
        if ($sub) {
            $base = max(time(), (int)$sub['expires_at']);
            $this->db->execute("UPDATE subscriptions SET expires_at=?,status='active',updated_at=? WHERE id=?", [$base + $days * 86400, time(), $sub['id']]);
            $this->outbox->enqueue('subscription.extend', 'extend:'.$sub['id'], ['subscription_id' => $sub['id']]);
        } else {
            $id = Database::id();
            $now = time();
            $this->db->execute(
                "INSERT INTO subscriptions(id,order_id,user_id,status,expires_at,created_at,is_trial,start_date,updated_at) VALUES(?,NULL,?,'active',?,?,0,?,?)",
                [$id, $userId, $now + $days * 86400, $now, $now, $now]
            );
            $this->outbox->enqueue('subscription.provision', 'provision:'.$id, ['subscription_id' => $id]);
        }
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), 'system', 'compensation.days', $userId, time()]);
    }
    private function grantTraffic(string $userId, int $gb, string $reason): void
    {
        $sub = $this->db->one("SELECT * FROM subscriptions WHERE user_id=? AND status IN ('active','trial','provisioning') ORDER BY created_at DESC LIMIT 1" . $this->db->lock(), [$userId]);
        if (!$sub) return;
        if ((int)$sub['traffic_limit_gb'] === 0) return;
        $this->db->execute('UPDATE subscriptions SET purchased_traffic_gb = purchased_traffic_gb + ? WHERE id=?', [$gb, $sub['id']]);
        $this->outbox->enqueue('subscription.traffic', 'traffic:'.$sub['id'].':'.$gb, ['subscription_id' => $sub['id'], 'traffic_gb' => $gb]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), 'system', 'compensation.traffic', $userId, time()]);
    }
    private function recipients(string $segment): array
    {
        $now = time();
        $rows = match ($segment) {
            'all' => $this->db->all('SELECT id FROM users WHERE disabled=0'),
            'active' => $this->db->all("SELECT DISTINCT s.user_id AS id FROM subscriptions s WHERE s.status IN ('active','trial') AND s.expires_at>?", [$now]),
            'inactive' => $this->db->all("SELECT id FROM users WHERE disabled=0 AND id NOT IN (SELECT user_id FROM subscriptions WHERE status IN ('active','trial') AND expires_at>?)", [$now]),
            'paid' => $this->db->all('SELECT id FROM users WHERE disabled=0 AND has_had_paid_subscription=1'),
            'trial' => $this->db->all("SELECT DISTINCT s.user_id AS id FROM subscriptions s WHERE s.is_trial=1 AND s.status IN ('active','trial')"),
            'telegram' => $this->db->all('SELECT id FROM users WHERE disabled=0 AND telegram_id IS NOT NULL'),
            default => [],
        };
        return array_values(array_filter(array_map(fn($r) => (string)($r['id'] ?? ''), $rows), fn($v) => $v !== ''));
    }
    public function list(int $limit = 50): array
    {
        return $this->db->all('SELECT * FROM compensations ORDER BY created_at DESC LIMIT ?', [$limit]);
    }
}
