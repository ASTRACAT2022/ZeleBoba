<?php
declare(strict_types=1);
namespace App\Infrastructure;

/**
 * Fixed-window rate limiter keyed by a bucket string (e.g. "ip:203.0.113.5",
 * "auth:user@x"). Rejects when a window is exhausted.
 *
 * Uses the existing rate_limits(bucket, hits, expires_at) table.
 */
final class RateLimiter
{
    public function __construct(private Database $db) {}

    /**
     * @return array{allowed:bool, remaining:int, retryAfter:int}
     */
    public function check(string $bucket, int $maxPerWindow, int $windowSeconds = 60): array
    {
        $now = time();
        $windowEnd = intdiv($now, $windowSeconds) * $windowSeconds + $windowSeconds;

        $row = $this->db->transaction(function () use ($bucket, $now, $windowEnd, $maxPerWindow, $windowSeconds) {
            $row = $this->db->one('SELECT * FROM rate_limits WHERE bucket=?', [$bucket]);
            if ($row === null || (int)$row['expires_at'] <= $now) {
                // New window: first hit consumes one, window expires after $windowSeconds.
                $this->db->execute(
                    'INSERT INTO rate_limits(bucket,hits,expires_at) VALUES(?,1,?)
                     ON CONFLICT(bucket) DO UPDATE SET hits=1, expires_at=excluded.expires_at',
                    [$bucket, $windowEnd]
                );
                return ['hits' => 1, 'expires_at' => $windowEnd];
            }
            $hits = (int)$row['hits'] + 1;
            $this->db->execute('UPDATE rate_limits SET hits=? WHERE bucket=?', [$hits, $bucket]);
            return ['hits' => $hits, 'expires_at' => (int)$row['expires_at']];
        });

        $hits = $row['hits'];
        $remaining = max(0, $maxPerWindow - $hits);
        $allowed = $hits <= $maxPerWindow;
        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'retryAfter' => $allowed ? 0 : max(1, (int)$row['expires_at'] - $now),
        ];
    }

    /** Convenience: throw BillingError if rate-limited. */
    public function enforce(string $bucket, int $maxPerWindow, int $windowSeconds = 60): void
    {
        $r = $this->check($bucket, $maxPerWindow, $windowSeconds);
        if (!$r['allowed']) {
            throw new \App\Billing\BillingError('Слишком много запросов. Повторите позже.');
        }
    }
}
