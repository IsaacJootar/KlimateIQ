<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A newly-registered user configures their workspace (the sectors they care about) before
 * they can use the rest of the app. `users.onboarded_at` is null until they finish or
 * explicitly skip the wizard; existing accounts were backfilled as already onboarded.
 *
 * Applied to the authenticated app route groups — not globally — so the wizard, auth, and
 * a few settings endpoints it needs stay reachable while onboarding is still pending.
 */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->onboarded_at === null && ! $this->isExempt($request)) {
            return redirect()->route('onboarding.show');
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        return $request->routeIs(
            'onboarding.*',
            'logout',
            'verification.*',
            'password.confirm',
            'password.update',
            'preferences.theme',
        );
    }
}
