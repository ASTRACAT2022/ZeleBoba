<?php
declare(strict_types=1);
namespace App\Infrastructure;
use PDO;
final class Database
{
    public readonly PDO $pdo;
    private int $depth=0;
    public function __construct(string $dsn, string $user = '', string $password = '')
    {
        $this->pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        if (!$this->postgres()) {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 10000');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }
    }
    public function postgres(): bool { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'; }
    public function execute(string $sql, array $params = []): int
    {
        $q = $this->pdo->prepare($sql); $q->execute($params); return $q->rowCount();
    }
    public function all(string $sql, array $params = []): array
    {
        $q = $this->pdo->prepare($sql); $q->execute($params); return $q->fetchAll();
    }
    public function one(string $sql, array $params = []): ?array { return $this->all($sql, $params)[0] ?? null; }
    public function lock(): string { return $this->postgres() ? ' FOR UPDATE' : ''; }
    public function transaction(callable $fn): mixed
    {
        if($this->depth>0){
            $name='nested_'.$this->depth++;$this->pdo->exec('SAVEPOINT '.$name);
            try{$result=$fn();$this->pdo->exec('RELEASE SAVEPOINT '.$name);return $result;}catch(\Throwable $e){$this->pdo->exec('ROLLBACK TO SAVEPOINT '.$name);$this->pdo->exec('RELEASE SAVEPOINT '.$name);throw $e;}finally{$this->depth--;}
        }
        // SQLite is only a development backend. IMMEDIATE serializes writers there.
        $this->postgres() ? $this->pdo->beginTransaction() : $this->pdo->exec('BEGIN IMMEDIATE');
        $this->depth=1;
        try {
            $result = $fn();
            $this->postgres() ? $this->pdo->commit() : $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->postgres() ? $this->pdo->rollBack() : $this->pdo->exec('ROLLBACK');
            throw $e;
        } finally { $this->depth=0; }
    }
    public function migrate(string $directory): void
    {
        $this->transaction(function () use ($directory) {
            if ($this->postgres()) $this->execute('SELECT pg_advisory_xact_lock(817421)');
            $this->execute('CREATE TABLE IF NOT EXISTS migrations (version VARCHAR(100) PRIMARY KEY, applied_at BIGINT NOT NULL)');
            $pg = $this->postgres();
            foreach (glob($directory.'/*.sql') as $path) {
                if ($this->one('SELECT version FROM migrations WHERE version = ?', [basename($path)])) continue;
                $sql = file_get_contents($path);
                // SQLite cannot DROP/ADD constraints: strip PostgreSQL-only blocks.
                if (!$pg) {
                    $sql = preg_replace('/-- \[PG\]\R.*?-- \[\/PG\]\R/s', '', $sql);
                    // Keep additive migrations portable to the development
                    // SQLite backend. PostgreSQL accepts IF NOT EXISTS on ADD
                    // COLUMN, while SQLite (used by the test suite) does not.
                    $sql = str_replace('ADD COLUMN IF NOT EXISTS', 'ADD COLUMN', $sql);
                    // 020_billing_core already introduced this column for
                    // SQLite before 029 made versioning idempotent in PG.
                    if (basename($path)==='029_protection_framework.sql') {
                        $sql = preg_replace('/^ALTER TABLE subscriptions\s+ADD COLUMN version .*;\s*$/m', '', $sql);
                    }
                    $sql = preg_replace('/extract\(epoch from now\(\)\)::bigint/', '0', $sql);
                    $sql = preg_replace('/^GRANT .*;\s*$/m', '', $sql);
                    $sql = preg_replace('/^ALTER DEFAULT PRIVILEGES .*;\s*$/m', '', $sql);
                }
                $this->pdo->exec($sql);
                $this->execute('INSERT INTO migrations VALUES (?, ?)', [basename($path), time()]);
            }
        });
    }
    public static function id(): string { return bin2hex(random_bytes(16)); }
}
