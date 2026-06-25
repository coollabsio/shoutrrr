<?php

use App\Http\Middleware\CaptureMcpWorkspaceSelection;
use App\Http\Middleware\EnsureEngagementEnabled;
use App\Http\Middleware\EnsureMetricsEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\WorkspaceMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'engagement.enabled' => EnsureEngagementEnabled::class,
            'metrics.enabled' => EnsureMetricsEnabled::class,
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
            AddLinkHeadersForPreloadedAssets::class,
            CaptureMcpWorkspaceSelection::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render exceptions as JSON for API paths and for any client that
        // explicitly asks for JSON (e.g. the composer's useHttp XHR autosave).
        // Inertia visits send `Accept: text/html` + `X-Inertia`, so their
        // validation-error redirect flow is unaffected.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
