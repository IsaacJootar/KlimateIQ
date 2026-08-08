<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catches an already-logged-in session whose account gets deactivated mid-session — the login
 * check in LoginRequest only stops a disabled account from signing in again, it doesn't touch
 * a session that was already active when an admin flips disabled_at.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->isDisabled()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'This account has been deactivated. Contact your platform administrator.',
            ]);
        }

        return $next($request);
    }
}
