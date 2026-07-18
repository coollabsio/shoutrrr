<?php

use App\Http\Middleware\CaptureMcpWorkspaceSelection;
use App\Http\Middleware\EnsureEngagementEnabled;
use App\Http\Middleware\EnsureFeedbackEnabled;
use App\Http\Middleware\EnsureMetricsEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\WorkspaceMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'engagement.enabled' => EnsureEngagementEnabled::class,
            'metrics.enabled' => EnsureMetricsEnabled::class,
            'feedback.enabled' => EnsureFeedbackEnabled::class,
        ]);

        $middleware->web(append: [
            // Outermost: sets the CSP nonce before the view renders and writes
            // the security headers onto the final response.
            SecurityHeaders::class,
            HandleAppearance::class,
            // WorkspaceMiddleware must run BEFORE HandleInertiaRequests: Inertia
            // resolves share() (which reads workspace-scoped shell data) inside
            // its own handle() before calling $next, so the workspace_id context
            // must be set first or scoped queries leak across workspaces.
            WorkspaceMiddleware::class,
            HandleInertiaRequests::class,
            // NOTE: AddLinkHeadersForPreloadedAssets is intentionally not
            // registered. It emits a `Link: rel=preload` HTTP header for each
            // Vite asset, but under Octane the underlying preloadedAssets list
            // persists across requests, so behind a TLS-terminating proxy the
            // header gets frozen with http:// URLs from an earlier request and
            // never reflects the per-request https scheme. The browser then
            // blocks those preloads as mixed content. The header is redundant
            // with the <link rel="modulepreload"> tags already rendered into the
            // document (which are generated per request and resolve to https).
            CaptureMcpWorkspaceSelection::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report unhandled exceptions to Sentry. No-op unless a DSN is set, so
        // self-hosted instances without Sentry are unaffected.
        Integration::handles($exceptions);

        // Render exceptions as JSON for API paths and for any client that
        // explicitly asks for JSON (e.g. the composer's useHttp XHR autosave).
        // Inertia visits send `Accept: text/html` + `X-Inertia`, so their
        // validation-error redirect flow is unaffected.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
