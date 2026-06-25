<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets a strict, nonce-based Content-Security-Policy plus the standard
 * hardening headers on every web response.
 *
 * The nonce is generated and registered with Vite *before* the response is
 * rendered so the blade root view and Vite-emitted tags can reference it, then
 * echoed into the CSP header afterward. Generating it per request (rather than
 * in a provider) keeps it correct under Octane, where providers boot once.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(32);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        foreach ($this->headers($nonce) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(string $nonce): array
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        // A strict nonce + 'strict-dynamic' CSP is fundamentally incompatible with
        // the Vite dev server: 'strict-dynamic' makes the browser ignore host
        // allowlists, so HMR scripts/styles served from the dev origin are blocked.
        // Enforce the CSP everywhere EXCEPT local development; the production policy
        // is what matters and is verifiable against a built deploy.
        if (! app()->environment('local')) {
            $headers['Content-Security-Policy'] = $this->contentSecurityPolicy($nonce);
        }

        if (app()->isProduction()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    protected function contentSecurityPolicy(string $nonce): string
    {
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
            // 'unsafe-inline' is required for React inline style attributes and the
            // <style> element recharts injects at runtime; style injection is a low
            // XSS risk and script-src remains strict.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }
}
