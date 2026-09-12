<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compatibility authentication for the cutover period.
 *
 * The session format stays identical to the live application, so a user can
 * move between the two frontends without another login.
 */
final class LegacyAuthService
{
    public function register(string $email, string $password): string
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || strlen($password) < 12 || strlen($password) > 128) {
            throw ValidationException::withMessages(['email' => 'Введите корректную почту и пароль от 12 до 128 символов.']);
        }

        $id = bin2hex(random_bytes(16));
        try {
            DB::table('users')->insert([
                'id' => $id,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID),
                'created_at' => time(),
            ]);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['email' => 'Не удалось создать аккаунт с этой почтой.']);
        }

        return $id;
    }

    public function attempt(string $email, string $password): string
    {
        $user = DB::table('users')->where('email', mb_strtolower(trim($email)))->first();
        $hash = (string) ($user->password_hash ?? '');
        $valid = $user && password_verify($password, $hash);
        if (!$valid && $user && str_starts_with($hash, 'pbkdf2_sha256$')) {
            $valid = self::verifyDjangoPbkdf2($password, $hash);
            if ($valid) DB::table('users')->where('id', $user->id)->update(['password_hash' => password_hash($password, PASSWORD_ARGON2ID)]);
        }
        if (!$user || (int) $user->disabled === 1 || !$valid) {
            throw ValidationException::withMessages(['email' => 'Неверная почта или пароль.']);
        }

        return $user->id;
    }

    private static function verifyDjangoPbkdf2(string $password, string $hash): bool
    {
        $parts = explode('$', $hash);
        if (count($parts) !== 4 || $parts[0] !== 'pbkdf2_sha256') return false;
        $iterations = (int) $parts[1];
        if ($iterations < 1 || $iterations > 5_000_000 || $parts[2] === '' || $parts[3] === '') return false;
        $expected = base64_decode($parts[3], true);
        if ($expected === false) return false;
        return hash_equals($expected, hash_pbkdf2('sha256', $password, $parts[2], $iterations, strlen($expected), true));
    }

    public function issue(string $userId): string
    {
        $token = bin2hex(random_bytes(32));
        DB::table('sessions')->insert([
            'id' => hash('sha256', $token),
            'user_id' => $userId,
            'csrf' => bin2hex(random_bytes(32)),
            'expires_at' => time() + 86400,
        ]);

        return $token;
    }

    public function logout(?string $token): void
    {
        if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)) {
            DB::table('sessions')->where('id', hash('sha256', $token))->delete();
        }
    }
}
