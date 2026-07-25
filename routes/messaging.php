<?php

declare(strict_types=1);

use App\Http\Controllers\Messaging\MessagingController;
use App\Models\Conversation;
use Illuminate\Support\Facades\Route;

// Route-model binding runs before WorkspaceMiddleware sets the Context, so scope
// the lookup to the authed user's current workspace (a foreign id 404s).
Route::bind('conversation', fn ($v) => Conversation::query()->withoutGlobalScopes()
    ->where('workspace_id', request()->user()?->current_workspace_id)->whereKey($v)->firstOrFail());

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('messages', [MessagingController::class, 'index'])->middleware('messages.enabled')->name('messages.index');
    Route::get('messages/{conversation}/thread', [MessagingController::class, 'thread'])->middleware('messages.enabled')->name('messages.thread');
    Route::post('messages/{conversation}/read', [MessagingController::class, 'markRead'])->middleware('messages.enabled')->name('messages.read');
    Route::post('messages/{conversation}/archive', [MessagingController::class, 'archive'])->middleware('messages.enabled')->name('messages.archive');
    Route::post('messages/{conversation}/reply', [MessagingController::class, 'respond'])->middleware(['messages.enabled', 'throttle:30,1'])->name('messages.respond');
});
