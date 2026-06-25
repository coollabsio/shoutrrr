<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEngagementEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('engagement.enabled'), 404);

        return $next($request);
    }
}
