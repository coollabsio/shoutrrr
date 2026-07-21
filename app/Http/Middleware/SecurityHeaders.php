<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\FileStorage;
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
        // The remote storage origin (empty on local/public-disk deployments) is
        // needed both to XHR-PUT direct-to-storage uploads / GET the video
        // editor's source (connect-src) and to play back a signed video URL in a
        // <video> element (media-src). Adding it only when the disk is remote
        // keeps local and public-disk deployments on the tightest policy.
        $storage = $this->storageOrigins();
        $connect = trim("'self' blob: ".implode(' ', array_merge($storage, $this->sentryOrigins())));
        $media = trim("'self' blob: ".implode(' ', $storage));
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
            // 'unsafe-inline' is required for React inline style attributes and the
            // <style> element recharts injects at runtime; style injection is a low
            // XSS risk and script-src remains strict.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            // blob: backs the composer's local video preview (URL.createObjectURL);
            // the storage origin backs playback of an already-uploaded video served
            // from a signed remote URL. Without media-src both fall through to
            // default-src 'self' and are blocked.
            "media-src {$media}",
            "font-src 'self' data:",
            "connect-src {$connect}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self' https:",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }

    /**
     * CSP source origins for the deployment's media storage host. Empty unless
     * the default disk is remote (s3), so local/public-disk deployments keep the
     * tightest policy. Derived from the configured public URL and API endpoint;
     * an s3 disk with neither configured (vanilla AWS, whose virtual-hosted,
     * region-derived presign host can't be predicted cheaply here) falls back to
     * `https:` so uploads and playback still work.
     *
     * @return list<string>
     */
    private function storageOrigins(): array
    {
        if (FileStorage::diskName() !== 's3') {
            return [];
        }

        $origins = [];

        foreach (['filesystems.disks.s3.url', 'filesystems.disks.s3.endpoint'] as $key) {
            $origin = $this->originOf((string) config($key));

            if ($origin !== null) {
                $origins[$origin] = $origin;
            }
        }

        return $origins === [] ? ['https:'] : array_values($origins);
    }

    /**
     * CSP source origin for the browser Sentry SDK. It POSTs event envelopes to
     * the ingest host encoded in the frontend DSN, so that origin must be in
     * connect-src or the browser blocks the reports. Empty when no DSN is set.
     *
     * @return list<string>
     */
    private function sentryOrigins(): array
    {
        $origin = $this->originOf((string) config('sentry-browser.dsn'));

        return $origin === null ? [] : [$origin];
    }

    /**
     * Reduce a URL to its CSP-source origin (`scheme://host[:port]`), or null if
     * it lacks a usable scheme and host.
     */
    private function originOf(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        return $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
    }
}
