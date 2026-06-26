<?php

declare(strict_types=1);

use App\Http\Controllers\Engagement\EngagementController;
use App\Http\Controllers\Engagement\ReplyImageEditController;
use App\Http\Controllers\Engagement\ReplyMediaController;
use App\Http\Controllers\Engagement\ReplyVideoUploadController;
use App\Models\PostMedia;
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

Route::bind('media', fn (string $value): PostMedia => PostMedia::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)
    ->whereKey($value)
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

    Route::middleware(['engagement.enabled', 'throttle:60,1'])->group(function (): void {
        Route::post('engagement/{reply}/like', [EngagementController::class, 'like'])->name('engagement.like');
        Route::delete('engagement/{reply}/like', [EngagementController::class, 'unlike'])->name('engagement.unlike');
        Route::delete('engagement/{reply}', [EngagementController::class, 'destroyReply'])->name('engagement.destroy');
    });

    Route::middleware(['engagement.enabled', 'throttle:60,1'])->group(function (): void {
        Route::post('engagement/{reply}/media', [ReplyMediaController::class, 'store'])->name('engagement.media.store');
        Route::delete('engagement/{reply}/media/{media}', [ReplyMediaController::class, 'destroy'])->name('engagement.media.destroy');
        Route::post('engagement/{reply}/media/video-url', [ReplyVideoUploadController::class, 'url'])->name('engagement.media.video-url');
        Route::post('engagement/{reply}/media/video', [ReplyVideoUploadController::class, 'store'])->name('engagement.media.video');
        Route::post('engagement/{reply}/image-edit', [ReplyImageEditController::class, 'store'])->name('engagement.image-edit.store');
        Route::put('engagement/{reply}/image-edit/{media}', [ReplyImageEditController::class, 'update'])->name('engagement.image-edit.update');
    });
});
