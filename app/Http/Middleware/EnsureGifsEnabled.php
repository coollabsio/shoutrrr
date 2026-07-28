<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Gifs\KlipyClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGifsEnabled
{
    public function __construct(private readonly KlipyClient $klipy) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->klipy->configured(), 404);

        return $next($request);
    }
}
