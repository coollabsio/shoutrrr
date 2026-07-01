<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Http\Controllers\AccountSets\AccountSetController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\Posts\CalendarController;
use App\Http\Controllers\Posts\ComposerController;
use App\Http\Controllers\Posts\NextSlotController;
use App\Http\Controllers\Posts\PostController;
use App\Http\Controllers\Posts\PostImageEditController;
use App\Http\Controllers\Posts\PostingScheduleController;
use App\Http\Controllers\Posts\PostMediaController;
use App\Http\Controllers\Posts\PostMetricsRefreshController;
use App\Http\Controllers\Posts\PostQueueController;
use App\Http\Controllers\Posts\PostScheduleController;
use App\Http\Controllers\Posts\PostShareController;
use App\Http\Controllers\Posts\PostTargetRetryController;
use App\Http\Controllers\Posts\PostVideoUploadController;
use App\Http\Controllers\Posts\PublishController;
use App\Models\AccountSet;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostShare;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Route;

// Route-model binding runs before WorkspaceMiddleware sets the Context, so scope
// each lookup to the authed user's current workspace (a foreign id 404s).
Route::bind('post', fn (string $value): Post => Post::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)
    ->where('status', '!=', PostStatus::Deleted->value)
    ->whereKey($value)
    ->firstOrFail());

Route::bind('media', fn (string $value): PostMedia => PostMedia::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)
    ->whereKey($value)
    ->firstOrFail());

Route::bind('account_set', fn (string $value): AccountSet => AccountSet::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)
    ->whereKey($value)
    ->firstOrFail());

Route::bind('target', fn (string $value): PostTarget => PostTarget::query()
    ->whereKey($value)
    ->whereHas('post', fn ($query) => $query
        ->where('workspace_id', request()->user()?->current_workspace_id)
        ->whereKey(request()->route('post') instanceof Post ? request()->route('post')->id : request()->route('post')))
    ->firstOrFail());

Route::bind('share', fn (string $value): PostShare => PostShare::query()->whereKey($value)->firstOrFail());

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('analytics', [AnalyticsController::class, 'index'])->middleware('metrics.enabled')->name('analytics.index');

    Route::get('calendar', [CalendarController::class, 'redirectToCurrent'])->name('calendar.index');
    Route::get('calendar/{yyyymm}', [CalendarController::class, 'show'])
        ->where('yyyymm', '\d{4}-\d{2}')->name('calendar.month');

    Route::get('queue', [PostingScheduleController::class, 'show'])->name('queue.show');
    Route::put('queue', [PostingScheduleController::class, 'update'])->name('queue.update');

    Route::get('posts/next-slot', [NextSlotController::class, 'show'])->name('posts.next-slot');
    Route::get('posts', [PostController::class, 'index'])->name('posts.index');

    Route::post('posts', [PostController::class, 'store'])->name('posts.store');
    Route::put('posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::get('posts/{post}', [ComposerController::class, 'show'])->name('posts.show');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
    Route::put('posts/{post}/schedule', [PostScheduleController::class, 'update'])->name('posts.schedule');
    Route::post('posts/{post}/queue', [PostQueueController::class, 'store'])->name('posts.queue');
    Route::post('posts/{post}/publish', [PublishController::class, 'store'])->name('posts.publish');
    Route::post('posts/{post}/targets/{target}/retry', [PostTargetRetryController::class, 'store'])->name('posts.targets.retry');
    Route::post('posts/{post}/metrics/refresh', [PostMetricsRefreshController::class, 'store'])->middleware('metrics.enabled')->name('posts.metrics.refresh');

    // Media uploads are throttled to bound abuse (presigned-URL minting / storage flooding).
    Route::middleware('throttle:60,1')->group(function (): void {
        Route::post('posts/{post}/media', [PostMediaController::class, 'store'])->name('posts.media.store');
        Route::patch('posts/{post}/media/{media}/alt', [PostMediaController::class, 'updateAlt'])->name('posts.media.alt');
        Route::post('posts/{post}/media/video-url', [PostVideoUploadController::class, 'url'])->name('posts.media.video-url');
        Route::post('posts/{post}/media/video', [PostVideoUploadController::class, 'store'])->name('posts.media.video');
        Route::post('posts/{post}/image-edit', [PostImageEditController::class, 'store'])->name('posts.image-edit.store');
        Route::put('posts/{post}/image-edit/{media}', [PostImageEditController::class, 'update'])->name('posts.image-edit.update');
    });
    Route::delete('posts/{post}/media/{media}', [PostMediaController::class, 'destroy'])->name('posts.media.destroy');

    Route::get('posts/{post}/shares', [PostShareController::class, 'index'])->name('posts.shares.index');
    Route::post('posts/{post}/shares', [PostShareController::class, 'store'])->name('posts.shares.store');
    Route::delete('posts/{post}/shares/{share}', [PostShareController::class, 'destroy'])->name('posts.shares.destroy');

    Route::post('account-sets', [AccountSetController::class, 'store'])->name('account-sets.store');
    Route::put('account-sets/{account_set}', [AccountSetController::class, 'update'])->name('account-sets.update');
    Route::delete('account-sets/{account_set}', [AccountSetController::class, 'destroy'])->name('account-sets.destroy');
});
