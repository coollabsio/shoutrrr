<?php

use Illuminate\Support\Facades\Route;

// Regression guard: the composer ShoutAI routes MUST run inside the `web`
// middleware group (session + cookies), or `auth` redirects (302) instead of
// streaming — a failure that actingAs()-based feature tests cannot see because
// they bind the user directly without resolving the session cookie.
it('serves composer ai routes through the web middleware group', function (string $name) {
    $route = Route::getRoutes()->getByName($name);

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('web');
})->with([
    'ai.composer.rewrite',
    'ai.composer.generate',
    'ai.composer.adapt',
]);
