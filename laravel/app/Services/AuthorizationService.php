<?php

namespace App\Services;

use App\Domain\Admin\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AuthorizationService
{
    /** @return list<string> */
    public function permissionsFor(string $userId): array
    {
        $user = DB::table('users')->where('id', $userId)->first(['role']);
        if ($user === null) {
            return [];
        }

        // Existing installations designate break-glass administrators through
        // users.role. Keep that explicit compatibility rule until legacy auth
        // itself is retired; all other staff access is role-assignment based.
        if ($user->role === 'admin') {
            return Permission::values();
        }

        if (! Schema::hasTable('admin_roles') || ! Schema::hasTable('user_roles')) {
            return [];
        }

        $roles = DB::table('user_roles as assignment')
            ->join('admin_roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $userId)
            ->where('assignment.is_active', 1)
            ->where('role.is_active', 1)
            ->where(static function ($query): void {
                $query->whereNull('assignment.expires_at')->orWhere('assignment.expires_at', '>', time());
            })
            ->pluck('role.permissions');

        $permissions = [];
        foreach ($roles as $encoded) {
            try {
                $rolePermissions = json_decode((string) $encoded, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (! is_array($rolePermissions)) {
                continue;
            }
            foreach ($rolePermissions as $permission) {
                if (is_string($permission) && in_array($permission, Permission::values(), true)) {
                    $permissions[$permission] = true;
                }
            }
        }

        return array_keys($permissions);
    }

    public function allows(string $userId, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($userId), true);
    }
}
