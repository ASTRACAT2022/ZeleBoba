<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\{Database,Outbox};
final class BroadcastService
{
    public function __construct(private Database $db, private Outbox $outbox) {}
    /** Create a broadcast job. Returns the row. */
    public function create(string $targetType, string $text, string $adminId, string $adminName, string $category = 'system'): array
    {
        if (mb_strlen($text) < 1 || mb_strlen($text) > 4000) throw new BillingError('Текст рассылки: 1–4000 символов.');
        if (!in_array($targetType, ['all','active','inactive','paid','trial','telegram','email'], true)) throw new BillingError('Некорректный сегмент.');
        $id = Database::id();
        $now = time();
        $this->db->execute(
            'INSERT INTO broadcast_history(id,target_type,message_text,total_count,status,admin_id,admin_name,category,created_at) VALUES(?,?,?,?,?,?,?,?,?)',
            [$id, $targetType, $text, 0, 'in_progress', $adminId, $adminName, $category, $now]
        );
        $this->outbox->enqueue('broadcast.run', 'broadcast:'.$id, ['broadcast_id' => $id]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $adminId, 'broadcast.created', $id, $now]);
        return $this->db->one('SELECT * FROM broadcast_history WHERE id=?', [$id]);
    }
    /** Run a broadcast: pick recipients and enqueue per-user sends. */
    public function run(string $broadcastId): void
    {
        $b = $this->db->one('SELECT * FROM broadcast_history WHERE id=?', [$broadcastId]);
        if (!$b || $b['status'] !== 'in_progress') return;
        $recipients = $this->recipients($b['target_type']);
        $this->db->execute('UPDATE broadcast_history SET total_count=? WHERE id=?', [count($recipients), $broadcastId]);
        foreach ($recipients as $tgId) {
            $this->outbox->enqueue('broadcast.send', 'bsend:'.$broadcastId.':'.$tgId, ['broadcast_id' => $broadcastId, 'chat_id' => $tgId, 'text' => $b['message_text']]);
        }
        $this->db->execute("UPDATE broadcast_history SET status='running' WHERE id=?", [$broadcastId]);
    }
    private function recipients(string $target): array
    {
        $now = time();
        $rows = match ($target) {
            'all' => $this->db->all("SELECT telegram_id FROM users WHERE telegram_id IS NOT NULL AND disabled=0"),
            'active' => $this->db->all("SELECT DISTINCT u.telegram_id FROM users u JOIN subscriptions s ON s.user_id=u.id WHERE u.telegram_id IS NOT NULL AND u.disabled=0 AND s.status IN ('active','trial') AND s.expires_at>?", [$now]),
            'inactive' => $this->db->all("SELECT telegram_id FROM users WHERE telegram_id IS NOT NULL AND disabled=0 AND id NOT IN (SELECT user_id FROM subscriptions WHERE status IN ('active','trial') AND expires_at>?)", [$now]),
            'paid' => $this->db->all("SELECT telegram_id FROM users WHERE telegram_id IS NOT NULL AND disabled=0 AND has_had_paid_subscription=1"),
            'trial' => $this->db->all("SELECT DISTINCT u.telegram_id FROM users u JOIN subscriptions s ON s.user_id=u.id WHERE u.telegram_id IS NOT NULL AND u.disabled=0 AND s.is_trial=1 AND s.status IN ('active','trial')"),
            'telegram' => $this->db->all("SELECT telegram_id FROM users WHERE telegram_id IS NOT NULL AND disabled=0"),
            default => [],
        };
        return array_values(array_filter(array_map(fn($r) => (string)($r['telegram_id'] ?? ''), $rows), fn($v) => $v !== ''));
    }
    /** Mark a single send as delivered or failed. */
    public function markSent(string $broadcastId, bool $ok): void
    {
        $this->db->execute('UPDATE broadcast_history SET sent_count=sent_count+1 WHERE id=?', [$broadcastId]);
        if (!$ok) $this->db->execute('UPDATE broadcast_history SET failed_count=failed_count+1 WHERE id=?', [$broadcastId]);
        $b = $this->db->one('SELECT * FROM broadcast_history WHERE id=?', [$broadcastId]);
        if ($b && (int)$b['sent_count'] + (int)$b['failed_count'] >= (int)$b['total_count']) {
            $this->db->execute("UPDATE broadcast_history SET status='completed',completed_at=? WHERE id=?", [time(), $broadcastId]);
        }
    }
    public function list(int $limit = 50): array
    {
        return $this->db->all('SELECT * FROM broadcast_history ORDER BY created_at DESC LIMIT ?', [$limit]);
    }
}
