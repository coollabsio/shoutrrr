<?php

declare(strict_types=1);

use App\Http\Controllers\Ai\ComposerAssistantController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php, so these inherit the `web` middleware group
// (session, cookies, CSRF) that the streamed `auth` requests rely on. They must
// NOT live in routes/ai.php — that is the MCP convention file, auto-loaded
// without the web group, which makes `auth` redirect (302) instead of streaming.
Route::middleware(['auth', 'ai.enabled', 'throttle:20,1'])->prefix('ai')->name('ai.')->group(function (): void {
    Route::post('composer/rewrite', [ComposerAssistantController::class, 'rewrite'])->name('composer.rewrite');
    Route::post('composer/generate', [ComposerAssistantController::class, 'generate'])->name('composer.generate');
    Route::post('composer/adapt', [ComposerAssistantController::class, 'adapt'])->name('composer.adapt');
});
