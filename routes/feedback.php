<?php

declare(strict_types=1);

use App\Http\Controllers\FeedbackController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::post('feedback', FeedbackController::class)
        ->middleware(['feedback.enabled', 'throttle:5,1'])
        ->name('feedback.store');
});
