<?php
declare(strict_types=1);
namespace App\Billing;

use App\Infrastructure\Database;

/** Immutable operational events shown on a customer's admin profile. */
final class CustomerTimeline
{
    public function __construct(private Database $db) {}

    /** @param array<string, scalar|null> $payload */
    public function record(string $userId, string $eventType, array $payload = [], ?int $occurredAt = null): void
    {
        $this->db->execute(
            'INSERT INTO customer_timeline(id,user_id,event_type,payload,occurred_at,recorded_at) VALUES(?,?,?,?,?,?)',
            [Database::id(), $userId, $eventType, json_encode($payload, JSON_THROW_ON_ERROR), $occurredAt ?? time(), (int) floor(microtime(true) * 1_000_000)]
        );
    }

    /** @return list<array{event_type:string,payload:array<string,mixed>,occurred_at:int}> */
    public function forUser(string $userId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 250));
        // Chronological order makes causality visible when several events share one second.
        $rows = $this->db->all('SELECT event_type,payload,occurred_at FROM customer_timeline WHERE user_id=? ORDER BY recorded_at ASC LIMIT ?', [$userId, $limit]);
        foreach ($rows as &$row) {
            $row['occurred_at'] = (int)$row['occurred_at'];
            $row['payload'] = json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR);
        }
        unset($row);
        return $rows;
    }
}
