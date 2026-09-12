<?php

namespace App\Http\Controllers;

use App\Services\LegacyAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function loginForm(): View { return view('auth.login'); }
    public function registerForm(): View { return view('auth.register'); }

    public function login(Request $request, LegacyAuthService $auth): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:128']]);
        return $this->authenticated($auth->attempt($data['email'], $data['password']), $auth);
    }

    public function register(Request $request, LegacyAuthService $auth): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'min:12', 'max:128']]);
        return $this->authenticated($auth->register($data['email'], $data['password']), $auth);
    }

    public function logout(Request $request, LegacyAuthService $auth): RedirectResponse
    {
        $auth->logout($request->cookie('zb_session'));
        return redirect('/login')->withoutCookie('zb_session');
    }

    private function authenticated(string $userId, LegacyAuthService $auth): RedirectResponse
    {
        return redirect('/')->withCookie(cookie('zb_session', $auth->issue($userId), 1440, '/', null, app()->isProduction(), true, false, 'lax'));
    }
}
