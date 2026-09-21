<?php
declare(strict_types=1);
namespace App\Infrastructure;

/**
 * Circuit breaker for outbound upstreams (Platega API, Remnawave API, etc.).
 *
 * States: closed -> open (after failureThreshold consecutive failures) -> half_open (after timeout)
 * In open state calls are rejected fast (fail-fast) to protect downstream.
 *
 * circuit_breakers(name PK, state, failures, opened_at, last_success, last_failure, updated_at)
 */
final class CircuitBreaker
{
    public const CLOSED = 'closed';
    public const OPEN = 'open';
    public const HALF_OPEN = 'half_open';

    public function __construct(
        private Database $db,
        private int $failureThreshold = 5,
        private int $cooldownSeconds = 300,
    ) {}

    /** Should we attempt a call to $name right now? */
    public function allow(string $name): bool
    {
        $b = $this->db->one('SELECT * FROM circuit_breakers WHERE name=? FOR UPDATE', [$name]);
        if ($b === null) {
            $this->init($name);
            return true;
        }
        if ($b['state'] === self::OPEN) {
            // Allow one probe after cooldown.
            if (time() - (int)$b['opened_at'] >= $this->cooldownSeconds) {
                $this->db->execute('UPDATE circuit_breakers SET state=?, updated_at=? WHERE name=?', [self::HALF_OPEN, time(), $name]);
                return true;
            }
            return false;
        }
        return true; // closed or half_open
    }

    /** Record a successful call. */
    public function success(string $name): void
    {
        $this->db->execute(
            "INSERT INTO circuit_breakers(name,state,failures,opened_at,last_success,last_failure,updated_at)\n" .
            "VALUES(?,?,0,NULL,?,NULL,?)\n" .
            "ON CONFLICT(name) DO UPDATE SET state='closed', failures=0, opened_at=NULL, last_success=excluded.last_success, updated_at=excluded.updated_at",
            [$name, self::CLOSED, time(), time()]
        );
    }

    /** Record a failed call; trip the breaker when threshold reached. */
    public function failure(string $name): void
    {
        $this->db->execute(
            "INSERT INTO circuit_breakers(name,state,failures,opened_at,last_success,last_failure,updated_at)\n" .
            "VALUES(?,?,1,NULL,NULL,?,?)\n" .
            "ON CONFLICT(name) DO UPDATE SET\n" .
            "  failures = circuit_breakers.failures + 1,\n" .
            "  last_failure = excluded.last_failure,\n" .
            "  updated_at = excluded.updated_at,\n" .
            "  state = CASE WHEN circuit_breakers.failures + 1 >= ? THEN 'open' ELSE circuit_breakers.state END,\n" .
            "  opened_at = CASE WHEN circuit_breakers.failures + 1 >= ? THEN excluded.last_failure ELSE circuit_breakers.opened_at END",
            [$name, self::CLOSED, time(), time(), $this->failureThreshold, $this->failureThreshold]
        );
    }

    public function state(string $name): array
    {
        return $this->db->one('SELECT * FROM circuit_breakers WHERE name=?', [$name])
            ?? ['name' => $name, 'state' => self::CLOSED, 'failures' => 0, 'opened_at' => null, 'last_success' => null, 'last_failure' => null, 'updated_at' => 0];
    }

    public function reset(string $name): void
    {
        $this->db->execute("UPDATE circuit_breakers SET state='closed', failures=0, opened_at=NULL, updated_at=? WHERE name=?", [time(), $name]);
    }

    private function init(string $name): void
    {
        $this->db->execute(
            'INSERT INTO circuit_breakers(name,state,failures,opened_at,last_success,last_failure,updated_at) VALUES(?,?,0,NULL,NULL,NULL,?) ON CONFLICT DO NOTHING',
            [$name, self::CLOSED, time()]
        );
    }
}
