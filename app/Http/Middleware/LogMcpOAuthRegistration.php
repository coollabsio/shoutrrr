<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogMcpOAuthRegistration
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('oauth/register')) {
            Log::info('MCP OAuth client registration callback', [
                'redirect_uri' => $request->input('redirect_uris.0'),
            ]);
        }

        return $next($request);
    }
}
