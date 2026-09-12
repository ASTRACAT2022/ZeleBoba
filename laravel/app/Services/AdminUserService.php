<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminUserService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function block(Request $request, string $actorId, string $userId): void
    {
        if ($actorId === $userId) {
            throw ValidationException::withMessages(['user' => 'Нельзя заблокировать собственную учётную запись.']);
        }

        DB::transaction(function () use ($request, $actorId, $userId): void {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            if ($user === null) {
                throw ValidationException::withMessages(['user' => 'Пользователь не найден.']);
            }
            if ((int) $user->disabled === 1) {
                return;
            }

            DB::table('users')->where('id', $userId)->update(['disabled' => 1]);
            $this->audit->record($request, $actorId, 'user.blocked', 'user', $userId, ['disabled' => 0], ['disabled' => 1]);
        });
    }
}
