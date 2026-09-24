<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Billing\BillingError;

/**
 * Optimistic concurrency control (CAS) for critical financial entities.
 *
 * Each row carries a `version` column. A write must supply the version it read;
 * if a concurrent writer bumped it first, the conditional UPDATE affects 0 rows
 * and we raise a conflict instead of silently overwriting.
 *
 * @see migrations/029_protection_framework.sql (version columns)
 */
final class OptimisticLock
{
    private const TABLES = ['orders', 'subscriptions', 'topups', 'transactions', 'payments', 'withdrawal_requests'];

    public function __construct(private Database $db) {}

    /**
     * Guarded version-bump update.
     *
     * @param string $table table name (allowlisted)
     * @param string $idColumn PK column (allowlisted below)
     * @param string $id PK value
     * @param array<string,mixed> $set columns=>values to set (version bumped automatically)
     * @param int $expectedVersion version read earlier
     * @return int rows affected (0 => version conflict)
     */
    public function update(string $table, string $idColumn, string $id, array $set, int $expectedVersion): int
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Неизвестная таблица для оптимистичной блокировки.');
        }
        if ($expectedVersion < 1) {
            throw new \InvalidArgumentException('Некорректная версия.');
        }
        $setCols = [];
        $params = [];
        foreach ($set as $c => $v) {
            if ($c === 'version' || $c === $idColumn) continue; // handled by CAS
            $setCols[] = '"' . $c . '" = ?';
            $params[] = $v;
        }
        $setCols[] = 'version = version + 1';
        $params[] = $id;
        $params[] = $expectedVersion;
        $sql = 'UPDATE "' . $table . '" SET ' . implode(', ', $setCols)
            . ' WHERE "' . $idColumn . '" = ? AND version = ?';
        return $this->db->execute($sql, $params);
    }

    /** Read current version of a row (or null if missing). */
    public function version(string $table, string $idColumn, string $id): ?int
    {
        $row = $this->db->one('SELECT version FROM "' . $table . '" WHERE "' . $idColumn . '" = ?', [$id]);
        return $row === null ? null : (int)$row['version'];
    }

    /** Assert the update was applied, else raise a conflict. Returns true on success. */
    public function assertApplied(int $rows, string $what): bool
    {
        if ($rows === 0) {
            throw new BillingError("Операция отклонена из-за конкурентного изменения ($what). Повторите.");
        }
        return true;
    }
}
