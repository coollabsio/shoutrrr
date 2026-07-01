<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUsageTrackingEnabled
{
    public function __construct(private readonly InstanceSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->settings->usageTrackingEnabled(), 404);

        return $next($request);
    }
}
