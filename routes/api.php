<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AccountSetsController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\ConnectedAccountsController;
use App\Http\Controllers\Api\V1\IntentsController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\NativeTrackingController;
use App\Http\Controllers\Api\V1\PlatformsController;
use App\Http\Controllers\Api\V1\PostActionsController;
use App\Http\Controllers\Api\V1\PostingScheduleController;
use App\Http\Controllers\Api\V1\PostsController;
use App\Http\Controllers\Api\V1\SharesController;
use App\Http\Controllers\Api\V1\SyncPipelinesController;
use App\Http\Middleware\RecordApiUsage;
use App\Http\Middleware\RequireWriteScope;
use App\Http\Middleware\ResolveApiWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', ResolveApiWorkspace::class, 'throttle:api', RecordApiUsage::class])
    ->group(function (): void {
        Route::get('platforms', [PlatformsController::class, 'index']);
        Route::get('platforms/{platform}/connect-url', [PlatformsController::class, 'connectUrl']);
        Route::get('connected-accounts', [ConnectedAccountsController::class, 'index']);
        Route::get('connected-accounts/{id}/reconnect-url', [ConnectedAccountsController::class, 'reconnectUrl']);
        Route::get('sync-pipelines', [SyncPipelinesController::class, 'index']);
        Route::get('posts', [PostsController::class, 'index']);
        Route::get('posts/{id}', [PostsController::class, 'show']);
        Route::get('account-sets', [AccountSetsController::class, 'index']);
        Route::get('calendar', [CalendarController::class, 'index']);
        Route::get('posting-schedule', [PostingScheduleController::class, 'show']);
        Route::get('posts/{id}/shares', [SharesController::class, 'index']);

        Route::middleware(RequireWriteScope::class)->group(function (): void {
            Route::post('intents/publish-now', [IntentsController::class, 'publishNow']);
            Route::post('intents/schedule-at', [IntentsController::class, 'scheduleAt']);

            Route::post('posts', [PostsController::class, 'store']);
            Route::patch('posts/{id}', [PostsController::class, 'update']);
            Route::delete('posts/{id}', [PostsController::class, 'destroy']);

            Route::post('posts/{id}/schedule', [PostActionsController::class, 'schedule']);
            Route::post('posts/{id}/queue', [PostActionsController::class, 'queue']);
            Route::post('posts/{id}/publish', [PostActionsController::class, 'publish']);
            Route::post('posts/{id}/targets/{targetId}/retry', [PostActionsController::class, 'retry']);

            Route::post('posts/{id}/shares', [SharesController::class, 'store']);
            Route::delete('posts/{id}/shares/{shareId}', [SharesController::class, 'destroy']);

            Route::post('media', [MediaController::class, 'store']);
            Route::post('media/video/upload-url', [MediaController::class, 'videoUploadUrl']);
            Route::post('media/video', [MediaController::class, 'storeVideo']);
            Route::delete('media/{mediaId}', [MediaController::class, 'destroy']);

            Route::post('account-sets', [AccountSetsController::class, 'store']);
            Route::patch('account-sets/{set}', [AccountSetsController::class, 'update']);
            Route::delete('account-sets/{set}', [AccountSetsController::class, 'destroy']);

            Route::patch('connected-accounts/{id}', [ConnectedAccountsController::class, 'update']);
            Route::delete('connected-accounts/{id}', [ConnectedAccountsController::class, 'destroy']);
            Route::post('connected-accounts/{id}/default', [ConnectedAccountsController::class, 'makeDefault']);
            Route::patch('connected-accounts/{id}/auto-repost', [ConnectedAccountsController::class, 'autoRepost']);
            Route::post('connected-accounts/{id}/native-tracking', [NativeTrackingController::class, 'store']);
            Route::delete('connected-accounts/{id}/native-tracking', [NativeTrackingController::class, 'destroy']);

            Route::post('sync-pipelines', [SyncPipelinesController::class, 'store']);
            Route::patch('sync-pipelines/{id}', [SyncPipelinesController::class, 'update']);
            Route::delete('sync-pipelines/{id}', [SyncPipelinesController::class, 'destroy']);

            Route::put('posting-schedule', [PostingScheduleController::class, 'update']);
        });
    });
