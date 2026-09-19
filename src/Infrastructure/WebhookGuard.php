<?php
declare(strict_types=1);
namespace App\Infrastructure;

/**
 * Webhook protection: replay detection + duplicate detection + schema validation.
 *
 * Prevents:
 *  - replaying an already-processed provider event (replay protection / idempotency)
 *  - processing duplicate notifications for the same payment id
 *  - malformed payloads (basic field presence/type validation before any side effect)
 *
 * webhook_events(id PK, provider, payload_sha256, processed, created_at, processed_at, UNIQUE(provider,id))
 */
final class WebhookGuard
{
    public function __construct(private Database $db) {}

    /**
     * Validate required scalar fields exist and match expected types.
     * @param array $payload decoded body
     * @param array<string,string> $rules fieldName => 'string'|'int'|'float' (present in $payload)
     * @return string[] list of validation problems (empty = valid)
     */
    public function validate(array $payload, array $rules): array
    {
        $problems = [];
        foreach ($rules as $field => $type) {
            if (!array_key_exists($field, $payload)) { $problems[] = "missing:$field"; continue; }
            $v = $payload[$field];
            $ok = match ($type) {
                'string' => is_string($v),
                'int' => is_int($v) || (is_string($v) && ctype_digit($v)),
                'float' => is_numeric($v),
                default => true,
            };
            if (!$ok) $problems[] = "type:$field:$type";
        }
        return $problems;
    }

    /**
     * Idempotency: claim a provider event for processing.
     *
     * @return string 'new' if this event id is first seen (caller should process)
     *               'duplicate' if already known (replay / duplicate — do NOT process)
     *               'same_payload' identical payload retry — safe to process again (idempotent webhook)
     * @throws \RuntimeException on schema conflict mismatch (same id different payload = tamper)
     */
    public function claim(string $provider, string $eventId, array $payload): string
    {
        $sha = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $existing = $this->db->one('SELECT * FROM webhook_events WHERE provider=? AND id=?', [$provider, $eventId]);

        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO webhook_events(id,provider,payload_sha256,processed,created_at,processed_at) VALUES(?,?,?,0,?,NULL)',
                [$eventId, $provider, $sha, time()]
            );
            return 'new';
        }
        if ($existing['payload_sha256'] === $sha) {
            // Same payload: if already processed, it's a benign replay => duplicate.
            return ((int)$existing['processed'] === 1) ? 'duplicate' : 'same_payload';
        }
        // Same event id, different payload => tampering / collision.
        $this->db->execute("UPDATE webhook_events SET processed=2 WHERE provider=? AND id=?", [$provider, $eventId]);
        throw new \RuntimeException("Webhook payload mismatch for $provider/$eventId (possible tamper)");
    }

    /** Mark a claimed event processed (after successful handling). */
    public function markProcessed(string $provider, string $eventId): void
    {
        $this->db->execute('UPDATE webhook_events SET processed=1, processed_at=? WHERE provider=? AND id=?', [time(), $provider, $eventId]);
    }

    /** Recent events for admin/observability. */
    public function recent(int $limit = 50): array
    {
        return $this->db->all('SELECT * FROM webhook_events ORDER BY created_at DESC LIMIT ?', [$limit]);
    }
}
