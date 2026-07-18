<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\FeedbackConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeedbackEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(FeedbackConfig::enabled(), 404);

        return $next($request);
    }
}
