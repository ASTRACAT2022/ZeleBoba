<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;
final class BackupService
{
    public function __construct(private Database $db, private string $backupDir) {}
    /** Create a full SQL dump backup. Returns the file path. */
    public function create(): string
    {
        if ($this->db->postgres()) throw new BillingError('Для PostgreSQL используйте scripts/backup.sh и восстановление по docs/operations.md.');
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0700, true)) throw new BillingError('Не удалось создать каталог бэкапов.');
        $file = rtrim($this->backupDir, '/').'/backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
        $out=$this->db->transaction(function() {
        $tables = $this->db->all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $out = "-- ZeleBoba backup ".gmdate('c')."\nPRAGMA foreign_keys=OFF;\nBEGIN;\n";
        foreach ($tables as $t) {
            $name = $t['name'];
            $out .= "DROP TABLE IF EXISTS \"$name\";\n";
            $create = $this->db->one("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$name]);
            if ($create && $create['sql']) $out .= $create['sql'].";\n";
            $rows = $this->db->all('SELECT * FROM "'.$name.'"');
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = array_map(fn($v) => $v === null ? 'NULL' : "'".str_replace("'", "''", (string)$v)."'", array_values($row));
                $out .= 'INSERT INTO "'.$name.'" ("'.implode('","', $cols).'") VALUES ('.implode(',', $vals).");\n";
            }
        }
        foreach ($this->db->all("SELECT sql FROM sqlite_master WHERE type IN ('index','trigger','view') AND sql IS NOT NULL ORDER BY type,name") as $object) $out.=$object['sql'].";\n";
        $out .= "COMMIT;\nPRAGMA foreign_keys=ON;\n";
        return $out;
        });
        $oldMask=umask(0077);
        try { $written=file_put_contents($file,$out,LOCK_EX); }
        finally { umask($oldMask); }
        if ($written!==strlen($out)) throw new BillingError('Не удалось сохранить бэкап.');
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), 'system', 'backup.created', basename($file), time()]);
        return $file;
    }
    public function list(): array
    {
        if (!is_dir($this->backupDir)) return [];
        $files = glob(rtrim($this->backupDir, '/').'/backup-*.sql') ?: [];
        $result = [];
        foreach ($files as $f) {
            $result[] = ['file' => basename($f), 'size' => filesize($f), 'created_at' => filemtime($f)];
        }
        usort($result, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        return $result;
    }
    public function restore(string $file): void
    {
        if ($this->db->postgres()) throw new BillingError('Восстановление PostgreSQL выполняется по docs/operations.md.');
        $path = rtrim($this->backupDir, '/').'/'.basename($file);
        if (!preg_match('/^backup-\d{8}-\d{6}(?:-[a-f0-9]{8})?\.sql$/D', basename($file)) || !is_file($path)) throw new BillingError('Бэкап не найден.');
        $sql = file_get_contents($path);
        if ($sql===false) throw new BillingError('Не удалось прочитать бэкап.');
        try { $this->db->pdo->exec($sql); }
        catch (\Throwable $e) {
            // SQL dumps open their own transaction.
            try { $this->db->pdo->exec('ROLLBACK'); } catch (\Throwable) {}
            throw $e;
        } finally { $this->db->pdo->exec('PRAGMA foreign_keys=ON'); }
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), 'system', 'backup.restored', basename($file), time()]);
    }
}
