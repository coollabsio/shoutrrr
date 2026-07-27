<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Gifs\KlipyClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGifsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(KlipyClient::class)->configured(), 404);

        return $next($request);
    }
}
