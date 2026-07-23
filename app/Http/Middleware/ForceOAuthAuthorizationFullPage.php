<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ForceOAuthAuthorizationFullPage
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethod('GET')
            && $request->routeIs('passport.authorizations.authorize')
            && $request->header('X-Inertia') !== null
        ) {
            return Inertia::location($request->fullUrl());
        }

        return $next($request);
    }
}
