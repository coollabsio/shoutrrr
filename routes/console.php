<?php

use App\Console\Commands\CaptureMetrics;
use App\Console\Commands\DispatchDuePosts;
use App\Console\Commands\DispatchDueReplyFetches;
use App\Console\Commands\PruneAbandonedUploads;
use App\Console\Commands\PruneMcpBindings;
use App\Console\Commands\RefreshExpiringTokens;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(DispatchDuePosts::class)->everyMinute()->withoutOverlapping();
Schedule::command(RefreshExpiringTokens::class)->hourly()->withoutOverlapping();
Schedule::command(PruneMcpBindings::class)->hourly();
Schedule::command(PruneAbandonedUploads::class)->hourly();

if (config('metrics.enabled')) {
    Schedule::command(CaptureMetrics::class)->everyFifteenMinutes()->withoutOverlapping();
}

if (config('engagement.enabled')) {
    Schedule::command(DispatchDueReplyFetches::class)->everyFifteenMinutes()->withoutOverlapping();
}
