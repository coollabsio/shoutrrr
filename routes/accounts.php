<?php

declare(strict_types=1);

use App\Http\Controllers\ConnectedAccounts\BlueskyConnectionController;
use App\Http\Controllers\ConnectedAccounts\BlueskyOAuthController;
use App\Http\Controllers\ConnectedAccounts\ConnectedAccountController;
use App\Http\Controllers\ConnectedAccounts\OAuthConnectionController;
use App\Http\Controllers\OAuth\BlueskyClientMetadataController;
use App\Models\ConnectedAccount;
use Illuminate\Support\Facades\Route;

Route::get('oauth/bluesky/client-metadata.json', BlueskyClientMetadataController::class)
    ->name('oauth.bluesky.metadata');

Route::get('oauth/bluesky/jwks.json', [BlueskyClientMetadataController::class, 'jwks'])
    ->name('oauth.bluesky.jwks');

// Route-model binding runs in SubstituteBindings, which executes before the
// appended WorkspaceMiddleware sets the workspace Context — so the model's global
// workspace scope is a no-op here. Scope the lookup to the authenticated user's
// current workspace explicitly so a foreign id 404s (rather than leaking existence).
Route::bind('account', fn (string $value): ConnectedAccount => ConnectedAccount::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)
    ->whereKey($value)
    ->firstOrFail());

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('accounts', [ConnectedAccountController::class, 'index'])->name('accounts.index');

    Route::get('accounts/connect/bluesky/oauth', [BlueskyOAuthController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('accounts.bluesky.oauth');

    Route::get('accounts/callback/bluesky/oauth', [BlueskyOAuthController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('accounts.bluesky.oauth.callback');

    Route::get('accounts/connect/{platform}', [OAuthConnectionController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('accounts.connect');

    Route::get('accounts/callback/{platform}', [OAuthConnectionController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('accounts.callback');

    Route::post('accounts/connect/bluesky', [BlueskyConnectionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('accounts.bluesky.store');

    Route::post('accounts/{account}/reconnect', [ConnectedAccountController::class, 'reconnect'])
        ->middleware('throttle:10,1')
        ->name('accounts.reconnect');

    Route::post('accounts/{account}/default', [ConnectedAccountController::class, 'makeDefault'])
        ->name('accounts.default');

    Route::delete('accounts/{account}', [ConnectedAccountController::class, 'destroy'])
        ->name('accounts.destroy');
});
