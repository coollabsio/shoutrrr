<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/workspace.php';
require __DIR__.'/auth.php';
require __DIR__.'/settings.php';
require __DIR__.'/accounts.php';
require __DIR__.'/posts.php';
