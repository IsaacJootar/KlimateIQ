<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFederalOversight
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check() || ! auth()->user()->hasFederalOversight()) {
            abort(403, 'This view is limited to agencies with federal oversight access.');
        }

        return $next($request);
    }
}
