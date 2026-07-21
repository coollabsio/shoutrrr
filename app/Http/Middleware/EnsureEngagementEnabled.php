<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEngagementEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(InstanceSettings::class)->engagementEnabled(), 404);

        return $next($request);
    }
}
