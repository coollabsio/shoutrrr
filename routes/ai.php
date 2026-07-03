<?php

declare(strict_types=1);

use App\Http\Middleware\RecordApiUsage;
use App\Mcp\Servers\ShoutrrrServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Mcp\Facades\Mcp;

// Throttle the OAuth authorize/token endpoints so consent and token-exchange
// can't be hammered (credential/consent abuse).
Route::middleware('throttle:20,1')->group(function (): void {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp', ShoutrrrServer::class)
    ->middleware(['auth:api', 'throttle:mcp', RecordApiUsage::class]);

// Browsers that GET /mcp would otherwise hit the package's bare "405 Method Not
// Allowed" handler, which renders as a confusing error page. Override the GET
// route (registered last, so it wins the lookup) to serve a friendly landing
// page to humans.
//
// IMPORTANT: MCP's Streamable HTTP transport reserves GET for the server->client
// SSE stream (clients send `Accept: text/event-stream`). The installed
// laravel/mcp does not implement that stream and returns 405 for GET, so we
// mirror 405 for anything that isn't an explicit browser navigation. We only
// hijack GET when the client clearly wants HTML and is NOT asking for an event
// stream — so a real MCP GET/SSE request is never served the landing page.
// Revisit this if laravel/mcp starts serving an SSE stream over GET.
Route::get('/mcp', function (Request $request) {
    $accept = (string) $request->header('Accept');
    $wantsHtml = str_contains($accept, 'text/html');
    $wantsEventStream = str_contains($accept, 'text/event-stream');

    if ($wantsHtml && ! $wantsEventStream) {
        return Inertia::render('mcp/landing');
    }

    return response('', 405)->header('Allow', 'POST');
})->name('mcp.landing');
