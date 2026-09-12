<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class RequireLegacySession
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->cookie('zb_session');
        if (!is_string($raw) || !preg_match('/^[a-f0-9]{64}$/D', $raw)) return redirect()->route('login');

        $user = DB::table('sessions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.id', hash('sha256', $raw))->where('s.expires_at', '>', time())->where('u.disabled', 0)
            ->select('u.id', 'u.email', 'u.telegram_id', 'u.role', 'u.promo_offer_discount_percent', 'u.promo_offer_discount_expires_at', 's.csrf', 's.admin_verified_until')->first();
        if (!$user) return redirect()->route('login');

        $request->attributes->set('legacyUser', $user);
        return $next($request);
    }
}
