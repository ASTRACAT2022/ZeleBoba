<?php
declare(strict_types=1);
namespace App\Billing;
use App\Infrastructure\Database;
final class RbacService
{
    public const PERMISSIONS = [
        'admin.view','admin.users','admin.plans','admin.promocodes','admin.broadcasts',
        'admin.channels','admin.landings','admin.contests','admin.polls','admin.campaigns',
        'admin.withdrawals','admin.settings','admin.sync','admin.backup','admin.reports',
        'admin.roles','admin.audit','admin.monitoring','admin.maintenance',
    ];
    public function __construct(private Database $db) {}
    /** Create a role. */
    public function createRole(string $name, string $description, int $level, array $permissions, string $actor): array
    {
        if (mb_strlen($name) < 1 || mb_strlen($name) > 100) throw new BillingError('Название роли: 1–100 символов.');
        foreach ($permissions as $p) {
            if (!in_array($p, self::PERMISSIONS, true)) throw new BillingError('Некорректное право: '.$p);
        }
        $id = Database::id();
        $this->db->execute(
            'INSERT INTO admin_roles(id,name,description,level,permissions,is_system,is_active,created_by,created_at) VALUES(?,?,?,?,?,0,1,?,?)',
            [$id, $name, $description !== '' ? $description : null, $level, json_encode($permissions, JSON_THROW_ON_ERROR), $actor, time()]
        );
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'role.created', $id, time()]);
        return $this->db->one('SELECT * FROM admin_roles WHERE id=?', [$id]);
    }
    public function listRoles(): array
    {
        return $this->db->all('SELECT * FROM admin_roles ORDER BY level DESC, created_at');
    }
    /** Assign a role to a user. */
    public function assignRole(string $userId, string $roleId, string $actor, ?int $expiresAt = null): void
    {
        $this->db->execute(
            'INSERT INTO user_roles(id,user_id,role_id,assigned_by,assigned_at,expires_at,is_active) VALUES(?,?,?,?,?,?,1) ON CONFLICT(user_id,role_id) DO UPDATE SET is_active=1,expires_at=excluded.expires_at',
            [Database::id(), $userId, $roleId, $actor, time(), $expiresAt]
        );
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'role.assigned', $userId, time()]);
    }
    public function revokeRole(string $userId, string $roleId, string $actor): void
    {
        $this->db->execute('UPDATE user_roles SET is_active=0 WHERE user_id=? AND role_id=?', [$userId, $roleId]);
        $this->db->execute('INSERT INTO audit_log VALUES(?,?,?,?,?)', [Database::id(), $actor, 'role.revoked', $userId, time()]);
    }
    /** Effective permissions for a user (role-based + legacy admin). */
    public function permissions(string $userId): array
    {
        $user = $this->db->one('SELECT role FROM users WHERE id=?', [$userId]);
        if (!$user) return [];
        if ($user['role'] === 'admin') return self::PERMISSIONS;
        $rows = $this->db->all(
            'SELECT r.permissions FROM user_roles ur JOIN admin_roles r ON r.id=ur.role_id WHERE ur.user_id=? AND ur.is_active=1 AND (ur.expires_at IS NULL OR ur.expires_at>?) AND r.is_active=1',
            [$userId, time()]
        );
        $perms = [];
        foreach ($rows as $row) {
            foreach (json_decode($row['permissions'], true, 512, JSON_THROW_ON_ERROR) as $p) $perms[] = $p;
        }
        return array_values(array_unique($perms));
    }
    public function can(string $userId, string $permission): bool
    {
        return in_array($permission, $this->permissions($userId), true);
    }
    /** Log an admin action to the immutable audit log. */
    public function logAction(string $userId, string $action, string $status, ?string $resourceType = null, ?string $resourceId = null, ?array $details = null, ?string $ip = null, ?string $userAgent = null, ?string $method = null, ?string $path = null): void
    {
        $this->db->execute(
            'INSERT INTO admin_audit_log(id,user_id,action,resource_type,resource_id,details,ip_address,user_agent,status,request_method,request_path,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
            [Database::id(), $userId, $action, $resourceType, $resourceId, $details !== null ? json_encode($details, JSON_THROW_ON_ERROR) : null, $ip, $userAgent, $status, $method, $path, time()]
        );
    }
    public function auditLog(int $limit = 100): array
    {
        return $this->db->all('SELECT * FROM admin_audit_log ORDER BY created_at DESC LIMIT ?', [$limit]);
    }
}
