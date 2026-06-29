<?php

declare(strict_types=1);

use App\Http\Controllers\Ai\ComposerAssistantController;
use App\Mcp\Servers\ShoutrrrServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Throttle the OAuth authorize/token endpoints so consent and token-exchange
// can't be hammered (credential/consent abuse).
Route::middleware('throttle:20,1')->group(function (): void {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp', ShoutrrrServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);

Route::middleware(['auth', 'ai.enabled', 'throttle:20,1'])->prefix('ai')->name('ai.')->group(function (): void {
    Route::post('composer/rewrite', [ComposerAssistantController::class, 'rewrite'])->name('composer.rewrite');
    Route::post('composer/generate', [ComposerAssistantController::class, 'generate'])->name('composer.generate');
    Route::post('composer/adapt', [ComposerAssistantController::class, 'adapt'])->name('composer.adapt');
});
