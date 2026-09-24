<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;
final class MaintenanceService
{
    public function __construct(private Database $db) {}
    /** Toggle maintenance mode (blocks purchases). */
    public function setMaintenance(bool $enabled, string $actor): void
    {
        $this->db->execute('INSERT INTO app_settings VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', ['MAINTENANCE_MODE', $enabled ? '1' : '0', time()]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, $enabled ? 'maintenance.on' : 'maintenance.off', '', time()]);
    }
    public function isMaintenance(): bool
    {
        return ($this->db->one("SELECT value FROM app_settings WHERE name='MAINTENANCE_MODE'")['value'] ?? '0') === '1';
    }
    /** Panel reachability check result. */
    public function setPanelStatus(bool $ok, string $detail): void
    {
        $this->db->execute('INSERT INTO app_settings VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', ['PANEL_REACHABLE', $ok ? '1' : '0', time()]);
        $this->db->execute('INSERT INTO app_settings VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at', ['PANEL_STATUS_DETAIL', $detail, time()]);
    }
    public function panelStatus(): array
    {
        return [
            'ok' => ($this->db->one("SELECT value FROM app_settings WHERE name='PANEL_REACHABLE'")['value'] ?? '1') === '1',
            'detail' => $this->db->one("SELECT value FROM app_settings WHERE name='PANEL_STATUS_DETAIL'")['value'] ?? '',
        ];
    }
}
