<?php

declare(strict_types=1);

use App\Mcp\Servers\ShoutrrrServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// This file is the laravel/mcp convention file, auto-loaded by McpServiceProvider
// (without the `web` middleware group). Keep ONLY MCP routes here. The app's
// ShoutAI composer routes live in routes/ai-assistant.php (required by web.php)
// so they get the web group (session/cookies) the SSE auth flow needs.

// Throttle the OAuth authorize/token endpoints so consent and token-exchange
// can't be hammered (credential/consent abuse).
Route::middleware('throttle:20,1')->group(function (): void {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp', ShoutrrrServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
