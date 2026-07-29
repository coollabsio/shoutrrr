<?php

declare(strict_types=1);

use App\Http\Controllers\Gifs\ConversationGifController;
use App\Http\Controllers\Messaging\ConversationMediaController;
use App\Http\Controllers\Messaging\ConversationVideoUploadController;
use App\Http\Controllers\Messaging\MessagingController;
use App\Models\Conversation;
use Illuminate\Support\Facades\Route;

// Route-model binding runs before WorkspaceMiddleware sets the Context, so scope
// the lookup to the authed user's current workspace (a foreign id 404s).
Route::bind('conversation', fn (string $value): Conversation => Conversation::query()
    ->withoutGlobalScopes()
    ->where('workspace_id', request()->user()?->current_workspace_id)
    ->whereKey($value)
    ->firstOrFail());

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('messages', [MessagingController::class, 'index'])->middleware('messages.enabled')->name('messages.index');
    Route::get('messages/{conversation}/thread', [MessagingController::class, 'thread'])->middleware('messages.enabled')->name('messages.thread');
    Route::post('messages/{conversation}/read', [MessagingController::class, 'markRead'])->middleware('messages.enabled')->name('messages.read');
    Route::post('messages/{conversation}/archive', [MessagingController::class, 'archive'])->middleware('messages.enabled')->name('messages.archive');
    Route::post('messages/{conversation}/reply', [MessagingController::class, 'respond'])->middleware(['messages.enabled', 'throttle:30,1'])->name('messages.respond');

    // Attachment endpoints, mirroring the engagement reply box's. The platform
    // guard is single-sourced in EnsureConversationSupportsDirectMessageMedia so
    // a Bluesky conversation cannot accumulate media on any of them. There is
    // deliberately no image-editor route here: a DM is not a published post.
    Route::middleware(['messages.enabled', 'conversation.supports-media', 'throttle:60,1'])->group(function (): void {
        Route::post('messages/{conversation}/media', [ConversationMediaController::class, 'store'])->name('messages.media.store');
        Route::patch('messages/{conversation}/media/{media}/alt', [ConversationMediaController::class, 'updateAlt'])->name('messages.media.alt');
        Route::delete('messages/{conversation}/media/{media}', [ConversationMediaController::class, 'destroy'])->name('messages.media.destroy');
        Route::post('messages/{conversation}/media/video-url', [ConversationVideoUploadController::class, 'url'])->name('messages.media.video-url');
        Route::post('messages/{conversation}/media/video', [ConversationVideoUploadController::class, 'store'])->name('messages.media.video');
        Route::post('messages/{conversation}/gifs', [ConversationGifController::class, 'store'])
            ->middleware('gifs.enabled')->name('messages.gifs.store');
    });
});
