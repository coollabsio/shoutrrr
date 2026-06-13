<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PublicShareController;
use App\Http\Middleware\NoIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

Route::get('/share/{token}', [PublicShareController::class, 'show'])
    ->middleware(NoIndex::class)
    ->name('share.show')
    ->where('token', '[A-Za-z0-9\-]+');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/workspace.php';
require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
require __DIR__.'/accounts.php';
require __DIR__.'/posts.php';
