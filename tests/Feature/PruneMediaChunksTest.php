<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

test('prunes chunk parts older than six hours and keeps recent ones', function (): void {
    $disk = Storage::fake('local');
    $disk->put('media-chunks/ws/old.part', 'x');
    $disk->put('media-chunks/ws/fresh.part', 'x');

    // Age the first file by setting mtime 7 hours back.
    touch($disk->path('media-chunks/ws/old.part'), Carbon::now()->subHours(7)->getTimestamp());

    $this->artisan('media:prune-chunks')->assertExitCode(0);

    expect($disk->exists('media-chunks/ws/old.part'))->toBeFalse()
        ->and($disk->exists('media-chunks/ws/fresh.part'))->toBeTrue();
});
