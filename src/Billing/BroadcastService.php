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
        $this->outbox->enqueue('broadcast.run', 'broadcast:'.$id.':1', ['broadcast_id' => $id]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $adminId, 'broadcast.created', $id, $now]);
        return $this->db->one('SELECT * FROM broadcast_history WHERE id=?', [$id]);
    }
    /** How many per-user broadcast sends to enqueue per broadcast.run tick. */
    private static int $chunk = 150;
    /** Delay (s) before the next broadcast.run is processed, so a chunk drains before the next is scheduled. */
    private static int $chunkDelay = 45;

    /** Run a broadcast: pick recipients and enqueue per-user sends in throttled chunks. */
    public function run(string $broadcastId): void
    {
        $b = $this->db->one('SELECT * FROM broadcast_history WHERE id=?', [$broadcastId]);
        if (!$b || !in_array($b['status'], ['in_progress', 'running'], true)) return;
        if ($b['status'] === 'in_progress') {
            $recipients = $this->recipients($b['target_type']);
            $this->db->execute('UPDATE broadcast_history SET total_count=? WHERE id=?', [count($recipients), $broadcastId]);
            $this->db->execute("UPDATE broadcast_history SET status='running' WHERE id=?", [$broadcastId]);
        } else {
            $recipients = $this->recipients($b['target_type']);
        }
        $remaining = array_values(array_filter(
            $recipients,
            fn($tgId) => !$this->db->one('SELECT 1 FROM outbox WHERE dedup_key=?', ['bsend:'.$broadcastId.':'.$tgId])
        ));
        $enqueued = 0;
        foreach ($remaining as $tgId) {
            if ($enqueued >= self::$chunk) break;
            $this->outbox->enqueue('broadcast.send', 'bsend:'.$broadcastId.':'.$tgId, ['broadcast_id' => $broadcastId, 'chat_id' => $tgId, 'text' => $b['message_text']]);
            $enqueued++;
        }
        if ($enqueued === 0) return;
        // More recipients remain: schedule the next chunk after a pause instead of flooding
        // the queue. Dedup keeps every recipient queued exactly once across ticks; each
        // continuation tick gets its own dedup key (broadcast:<id>:<n>) because the first
        // tick key is consumed (done) once the worker processes it.
        if (count($remaining) > $enqueued) {
            $this->outbox->enqueue('broadcast.run', 'broadcast:'.$broadcastId.':'.(string)$this->nextBatchKey($broadcastId), ['broadcast_id' => $broadcastId], self::$chunkDelay);
        }
    }
    private function nextBatchKey(string $broadcastId): int
    {
        $row = $this->db->one("SELECT count(*) c FROM outbox WHERE topic='broadcast.run' AND dedup_key LIKE ?", ['broadcast:'.$broadcastId.':%']);
        return (int)($row['c'] ?? 0) + 1;
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
