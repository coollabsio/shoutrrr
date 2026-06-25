<?php

declare(strict_types=1);

use App\Http\Controllers\Engagement\EngagementController;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use Illuminate\Support\Facades\Route;

// Route-model binding runs before WorkspaceMiddleware sets the Context, so scope
// the lookup to the authed user's current workspace (a foreign id 404s).
Route::bind('reply', fn (string $value): PostTargetReply => PostTargetReply::query()
    ->withoutGlobalScopes()
    ->where('workspace_id', request()->user()?->current_workspace_id)
    ->whereKey($value)
    ->firstOrFail());

Route::bind('target', fn (string $value): PostTarget => PostTarget::query()
    ->whereKey($value)
    ->whereHas('post', fn ($q) => $q->where('workspace_id', request()->user()?->current_workspace_id))
    ->firstOrFail());

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('engagement', [EngagementController::class, 'index'])
        ->middleware('engagement.enabled')
        ->name('engagement.index');
    Route::get('engagement/{reply}/thread', [EngagementController::class, 'thread'])
        ->middleware('engagement.enabled')->name('engagement.thread');
    Route::post('engagement/{reply}/read', [EngagementController::class, 'markRead'])
        ->middleware('engagement.enabled')->name('engagement.read');
    Route::post('engagement/{reply}/archive', [EngagementController::class, 'archive'])
        ->middleware('engagement.enabled')->name('engagement.archive');
    Route::post('engagement/{reply}/reply', [EngagementController::class, 'respond'])
        ->middleware(['engagement.enabled', 'throttle:30,1'])->name('engagement.respond');
    Route::post('engagement/posts/{target}/refresh', [EngagementController::class, 'refresh'])
        ->middleware('engagement.enabled')->name('engagement.refresh');
});
