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
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0700, true)) throw new BillingError('Не удалось создать каталог бэкапов.');
        $file = rtrim($this->backupDir, '/').'/backup-'.gmdate('Ymd-His').'.sql';
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
        $out .= "COMMIT;\n";
        file_put_contents($file, $out, LOCK_EX);
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
        $path = rtrim($this->backupDir, '/').'/'.basename($file);
        if (!preg_match('/^backup-\d{8}-\d{6}\.sql$/D', basename($file)) || !is_file($path)) throw new BillingError('Бэкап не найден.');
        $sql = file_get_contents($path);
        $this->db->pdo->exec($sql);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), 'system', 'backup.restored', basename($file), time()]);
    }
}
