<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\InstanceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiEnabled
{
    public function __construct(private InstanceSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->settings->aiConfigured(), 404);

        return $next($request);
    }
}
