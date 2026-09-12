<?php

namespace App\Http\Middleware;

use App\Services\AuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->attributes->get('legacyUser');
        if (! is_object($user) || ! isset($user->id) || ! app(AuthorizationService::class)->allows((string) $user->id, $permission)) {
            abort(403);
        }

        return $next($request);
    }
}
