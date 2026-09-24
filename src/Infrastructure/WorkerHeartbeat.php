<?php
declare(strict_types=1);
namespace App\Infrastructure;

/**
 * Worker heartbeat + stuck-job watchdog (Stage 1).
 *
 * Workers periodically call beat() with their current job. The watchdog
 * (run from scheduler) flags workers whose last beat is older than $staleSeconds.
 *
 * worker_heartbeats(worker PK, last_beat, job, updated_at)
 */
final class WorkerHeartbeat
{
    public function __construct(private Database $db) {}

    /** Called by a worker at the start of each job iteration. */
    public function beat(string $worker, string $job = ''): void
    {
        $this->db->execute(
            'INSERT INTO worker_heartbeats(worker,last_beat,job,updated_at) VALUES(?,?,?,?)
             ON CONFLICT(worker) DO UPDATE SET last_beat=excluded.last_beat, job=excluded.job, updated_at=excluded.updated_at',
            [$worker, time(), $job, time()]
        );
    }

    /** List workers that have gone stale (likely stuck or dead). */
    public function stale(int $staleSeconds = 300): array
    {
        return $this->db->all('SELECT * FROM worker_heartbeats WHERE last_beat <= ? ORDER BY last_beat', [time() - $staleSeconds]);
    }

    /** All known workers with age. */
    public function all(): array
    {
        $rows = $this->db->all('SELECT *, (? - last_beat) AS age_seconds FROM worker_heartbeats ORDER BY worker', [time()]);
        foreach ($rows as &$r) { $r['stale'] = (int)$r['age_seconds'] > 300; }
        return $rows;
    }

    public function beatNow(string $worker): void { $this->beat($worker, ''); }
}
