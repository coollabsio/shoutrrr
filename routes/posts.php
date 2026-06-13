<?php

declare(strict_types=1);

use App\Http\Controllers\AccountSets\AccountSetController;
use App\Http\Controllers\Posts\ComposerController;
use App\Http\Controllers\Posts\PostController;
use App\Http\Controllers\Posts\PostMediaController;
use App\Http\Controllers\Posts\PostQueueController;
use App\Http\Controllers\Posts\PostScheduleController;
use App\Http\Controllers\Posts\PostTargetRetryController;
use App\Http\Controllers\Posts\PublishController;
use App\Models\AccountSet;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Route;

// Route-model binding runs before WorkspaceMiddleware sets the Context, so scope
// each lookup to the authed user's current workspace (a foreign id 404s).
Route::bind('post', fn (string $value): Post => Post::query()
    ->where('workspace_id', request()->user()?->current_workspace_id)
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

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('compose/{post}', [ComposerController::class, 'show'])->name('compose.show');
    Route::get('posts', [ComposerController::class, 'index'])->name('posts.index');

    Route::post('posts', [PostController::class, 'store'])->name('posts.store');
    Route::put('posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::get('posts/{post}', [PostController::class, 'showJson'])->name('posts.show');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
    Route::put('posts/{post}/schedule', [PostScheduleController::class, 'update'])->name('posts.schedule');
    Route::post('posts/{post}/queue', [PostQueueController::class, 'store'])->name('posts.queue');
    Route::post('posts/{post}/publish', [PublishController::class, 'store'])->name('posts.publish');
    Route::post('posts/{post}/targets/{target}/retry', [PostTargetRetryController::class, 'store'])->name('posts.targets.retry');

    Route::post('posts/{post}/media', [PostMediaController::class, 'store'])->name('posts.media.store');
    Route::delete('posts/{post}/media/{media}', [PostMediaController::class, 'destroy'])->name('posts.media.destroy');

    Route::post('account-sets', [AccountSetController::class, 'store'])->name('account-sets.store');
    Route::put('account-sets/{account_set}', [AccountSetController::class, 'update'])->name('account-sets.update');
    Route::delete('account-sets/{account_set}', [AccountSetController::class, 'destroy'])->name('account-sets.destroy');
});
