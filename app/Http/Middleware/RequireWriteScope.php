<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject mutating requests made with a read-only API key, independent of the
 * acting member's workspace role.
 */
class RequireWriteScope
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->tokenCant('write')) {
            abort(403, 'This API key is read-only.');
        }

        return $next($request);
    }
}
