<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountSetsController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\ConnectedAccountsController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\PostActionsController;
use App\Http\Controllers\Api\V1\PostingScheduleController;
use App\Http\Controllers\Api\V1\PostsController;
use App\Http\Middleware\RecordApiUsage;
use App\Http\Middleware\RequireWriteScope;
use App\Http\Middleware\ResolveApiWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', ResolveApiWorkspace::class, 'throttle:api', RecordApiUsage::class])
    ->group(function (): void {
        Route::get('connected-accounts', [ConnectedAccountsController::class, 'index']);
        Route::get('posts', [PostsController::class, 'index']);
        Route::get('posts/{id}', [PostsController::class, 'show']);
        Route::get('account-sets', [AccountSetsController::class, 'index']);
        Route::get('calendar', [CalendarController::class, 'index']);
        Route::get('posting-schedule', [PostingScheduleController::class, 'show']);

        Route::middleware(RequireWriteScope::class)->group(function (): void {
            Route::post('posts', [PostsController::class, 'store']);
            Route::patch('posts/{id}', [PostsController::class, 'update']);
            Route::delete('posts/{id}', [PostsController::class, 'destroy']);

            Route::post('posts/{id}/schedule', [PostActionsController::class, 'schedule']);
            Route::post('posts/{id}/queue', [PostActionsController::class, 'queue']);
            Route::post('posts/{id}/publish', [PostActionsController::class, 'publish']);
            Route::post('posts/{id}/targets/{targetId}/retry', [PostActionsController::class, 'retry']);

            Route::post('media', [MediaController::class, 'store']);
            Route::delete('media/{mediaId}', [MediaController::class, 'destroy']);

            Route::post('account-sets', [AccountSetsController::class, 'store']);
            Route::patch('account-sets/{set}', [AccountSetsController::class, 'update']);
            Route::delete('account-sets/{set}', [AccountSetsController::class, 'destroy']);
        });
    });
