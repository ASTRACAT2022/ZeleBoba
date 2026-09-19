<?php
declare(strict_types=1);
namespace App\Infrastructure;
use App\Billing\BillingError;

/**
 * Kill switches + safe mode (Stage 1 hardening).
 *
 * Global + per-provider kill switches gate purchases and provisioning.
 * Safe mode freezes the whole financial surface (all purchases + withdrawals)
 * while keeping reads/admin available.
 *
 * kill_switches(name PK, enabled, actor, reason, created_at)
 * app_settings: SAFE_MODE
 */
final class KillSwitch
{
    public const SAFE_MODE = 'SAFE_MODE';

    public function __construct(private Database $db) {}

    /** Enable/disable a named kill switch. */
    public function set(string $name, bool $enabled, string $actor, string $reason = ''): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,63}$/', $name)) {
            throw new BillingError('Некорректное имя переключателя.');
        }
        $this->db->execute(
            'INSERT INTO kill_switches(name,enabled,actor,reason,created_at) VALUES(?,?,?,?,?)
             ON CONFLICT(name) DO UPDATE SET enabled=excluded.enabled, actor=excluded.actor, reason=excluded.reason, created_at=excluded.created_at',
            [$name, $enabled ? 1 : 0, $actor, $reason, time()]
        );
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',
            [Database::id(), $actor, ($enabled ? 'kill_switch.on.' : 'kill_switch.off.').$name, $reason, time()]);
    }

    /** Is a named switch engaged? Unknown switch => false (fail-open by default). */
    public function engaged(string $name): bool
    {
        $row = $this->db->one('SELECT enabled FROM kill_switches WHERE name=?', [$name]);
        return $row !== null && (int)$row['enabled'] === 1;
    }

    /** Safe mode = hard freeze of the whole financial surface. */
    public function safeMode(): bool
    {
        return ($this->db->one("SELECT value FROM app_settings WHERE name=?", [self::SAFE_MODE])['value'] ?? '0') === '1';
    }
    public function setSafeMode(bool $enabled, string $actor, string $reason = ''): void
    {
        $this->db->execute('INSERT INTO app_settings VALUES(?,?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at',
            [self::SAFE_MODE, $enabled ? '1' : '0', time()]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)',
            [Database::id(), $actor, $enabled ? 'safe_mode.on' : 'safe_mode.off', $reason, time()]);
    }

    /**
     * Gate an order creation / topup / withdrawal.
     * @param string $provider payment provider id
     */
    public function assertCanPurchase(?string $provider = null): void
    {
        if ($this->safeMode()) {
            throw new BillingError('Покупки временно приостановлены (safe mode).');
        }
        if ($this->engaged('global_purchases')) {
            throw new BillingError('Покупки временно отключены.');
        }
        if ($provider !== null && $this->engaged('provider.'.$provider)) {
            throw new BillingError('Оплата через этот способ временно недоступна.');
        }
    }

    /** Gate Remnawave provisioning. */
    public function assertCanProvision(): void
    {
        if ($this->engaged('remnawave_provision') || $this->engaged('global_purchases') || $this->safeMode()) {
            throw new BillingError('Выдача VPN временно приостановлена.');
        }
    }

    /** Gate withdrawal requests. */
    public function assertCanWithdraw(): void
    {
        if ($this->safeMode() || $this->engaged('financial_freeze')) {
            throw new BillingError('Вывод средств временно приостановлен.');
        }
    }

    /** List all switches + safe mode for admin UI. */
    public function all(): array
    {
        $switches = $this->db->all('SELECT * FROM kill_switches ORDER BY name');
        return ['safe_mode' => $this->safeMode(), 'switches' => $switches];
    }
}
