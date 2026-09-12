<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifier;

/**
 * Compatibility shim for routes/web.php references to App\Http\Middleware\VerifyCsrfToken.
 *
 * Laravel 12 ships the verifier at Illuminate\Foundation\Http\Middleware\VerifyCsrfToken.
 * Pre-Laravel-11 skeletons placed it at App\Http\Middleware\VerifyCsrfToken, and some
 * route files still reference that fully qualified name. Without this class, every POST
 * request fails with 419 CSRF token mismatch because the alias resolves to nothing.
 */
class VerifyCsrfToken extends BaseVerifier
{
    //
}
