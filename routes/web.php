<?php

use App\Http\Controllers\CommandSearchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PublicShareController;
use App\Http\Controllers\WorkspaceMentionController;
use App\Http\Middleware\NoIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

Route::get('/share/{token}', [PublicShareController::class, 'show'])
    ->middleware([NoIndex::class, 'throttle:30,1'])
    ->name('share.show')
    ->where('token', '[A-Za-z0-9\-]+');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('workspace-mentions', [WorkspaceMentionController::class, 'store'])->name('workspace-mentions.store');
});

Route::middleware('auth')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::get('command-search', CommandSearchController::class)
        ->middleware('throttle:60,1')
        ->name('command-search');
});

require __DIR__.'/workspace.php';
require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
require __DIR__.'/accounts.php';
require __DIR__.'/posts.php';
require __DIR__.'/engagement.php';
