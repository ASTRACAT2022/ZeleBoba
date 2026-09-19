<?php
declare(strict_types=1);
namespace App\Infrastructure;

final class FourEyes
{
    /** Actions that always require two-person approval. */
    public const SENSITIVE = [
        'kill_switch.toggle',      // toggling freeze/provision switches
        'backup.restore',          // DB restore — irreversible overwrite
        'role.grant',              // privilege escalation
        'balance.adjust_manual',   // large manual balance correction
        'withdrawal.approve',      // payout approval
    ];

    public function __construct(private Database $db, private int $idBits = 0) {}

    public function requiresApproval(string $action): bool
    {
        return in_array($action, self::SENSITIVE, true);
    }

    /** Enqueue a pending approval request. Returns queue id. */
    public function request(string $action, string $actor, array $payload, string $reason = ''): string
    {
        $id = substr(bin2hex(random_bytes(16)), 0, 32);
        $this->db->execute(
            'INSERT INTO approval_queue (id, action, actor, payload, status, requested_at, decided_at, decided_by)
             VALUES (?,?,?,?,?,?,NULL,NULL)',
            [$id, $action, $actor, json_encode($payload, JSON_UNESCAPED_UNICODE), 'pending', time()]
        );
        return $id;
    }

    public function pending(int $limit = 100): array
    {
        return $this->db->all(
            'SELECT id, action, actor, payload, requested_at FROM approval_queue
             WHERE status = ? ORDER BY requested_at ASC LIMIT ?',
            ['pending', $limit]
        );
    }

    public function get(string $id): ?array
    {
        $row = $this->db->one('SELECT * FROM approval_queue WHERE id = ?', [$id]);
        return $row ?: null;
    }

    /** Approve a pending request. Only another operator (≠ requester) may decide. */
    public function approve(string $id, string $reviewer): void
    {
        $row = $this->get($id);
        if (!$row || $row['status'] !== 'pending') throw new \App\Billing\BillingError('Заявка не найдена или уже решена.');
        if ($row['actor'] === $reviewer) throw new \App\Billing\BillingError('Нельзя подтверждать собственное действие.');
        $this->db->execute(
            'UPDATE approval_queue SET status=?, decided_at=?, decided_by=? WHERE id=? AND status=?',
            ['approved', time(), $reviewer, $id, 'pending']
        );
    }

    public function reject(string $id, string $reviewer): void
    {
        $row = $this->get($id);
        if (!$row || $row['status'] !== 'pending') throw new \App\Billing\BillingError('Заявка не найдена или уже решена.');
        $this->db->execute(
            'UPDATE approval_queue SET status=?, decided_at=?, decided_by=? WHERE id=? AND status=?',
            ['rejected', time(), $reviewer, $id, 'pending']
        );
    }

    /**
     * Enforce four-eyes: if the action is sensitive AND the actor is the sole
     * admin (no second reviewer available), enqueue and halt — the action cannot
     * proceed until a second operator approves. Returns null when enqueued.
     */
    public function guard(string $action, string $actor, array $payload, string $reason = ''): ?string
    {
        if (!$this->requiresApproval($action)) return null;
        // If there is genuinely only one admin, we still enqueue so the action
        // is gated — safer to block than to allow unilateral irreversible change.
        return $this->request($action, $actor, $payload, $reason);
    }

    public function history(int $limit = 50): array
    {
        return $this->db->all(
            'SELECT id, action, actor, status, requested_at, decided_at, decided_by
             FROM approval_queue ORDER BY requested_at DESC LIMIT ?',
            [$limit]
        );
    }
}
