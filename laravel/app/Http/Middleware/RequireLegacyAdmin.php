<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transitional guard: Laravel reads the existing `zb_session` cookie until
 * login is migrated. It deliberately accepts only an MFA-verified admin.
 */
final class RequireLegacyAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->cookie('zb_session');
        if (! is_string($raw) || ! preg_match('/^[a-f0-9]{64}$/D', $raw)) {
            abort(401);
        }

        $user = DB::table('sessions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', hash('sha256', $raw))
            ->where('s.expires_at', '>', time())
            ->where('u.disabled', 0)
            ->select('u.id', 'u.email', 'u.role', 's.csrf', 's.admin_verified_until')
            ->first();

        // Authentication (including MFA step-up) is separate from
        // authorization. The following permission middleware determines which
        // staff role may perform an individual action.
        if (! $user || (int) $user->admin_verified_until < time()) {
            abort(403);
        }

        if (app(AuthorizationService::class)->permissionsFor((string) $user->id) === []) {
            abort(403);
        }

        $request->attributes->set('legacyUser', $user);

        return $next($request);
    }
}
