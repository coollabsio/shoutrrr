<?php

declare(strict_types=1);

use App\Http\Controllers\Engagement\EngagementController;
use App\Models\PostTargetReply;
use Illuminate\Support\Facades\Route;

// Route-model binding runs before WorkspaceMiddleware sets the Context, so scope
// the lookup to the authed user's current workspace (a foreign id 404s).
Route::bind('reply', fn (string $value): PostTargetReply => PostTargetReply::query()
    ->withoutGlobalScopes()
    ->where('workspace_id', request()->user()?->current_workspace_id)
    ->whereKey($value)
    ->firstOrFail());

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('engagement', [EngagementController::class, 'index'])
        ->middleware('engagement.enabled')
        ->name('engagement.index');
});
