<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ConnectedAccountsController;
use App\Http\Middleware\RecordApiUsage;
use App\Http\Middleware\ResolveApiWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', ResolveApiWorkspace::class, 'throttle:api', RecordApiUsage::class])
    ->group(function (): void {
        Route::get('connected-accounts', [ConnectedAccountsController::class, 'index']);
    });
