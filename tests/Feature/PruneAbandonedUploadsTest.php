<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

test('prunes tmp/media files older than 24 hours and keeps recent ones', function (): void {
    $disk = Storage::fake(config('filesystems.default'));
    $workspaceId = '11111111-1111-4111-8111-111111111111';

    $oldFile = 'tmp/media/'.$workspaceId.'/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee.mp4';
    $freshFile = 'tmp/media/'.$workspaceId.'/ffffffff-eeee-dddd-cccc-bbbbbbbbbbbb.mp4';

    $disk->put($oldFile, 'x');
    $disk->put($freshFile, 'x');

    // Age the old file by setting its mtime 25 hours in the past.
    touch($disk->path($oldFile), Carbon::now()->subHours(25)->getTimestamp());

    $this->artisan('media:prune-uploads')->assertExitCode(0);

    expect($disk->exists($oldFile))->toBeFalse()
        ->and($disk->exists($freshFile))->toBeTrue();
});

test('outputs the count of pruned files', function (): void {
    $disk = Storage::fake(config('filesystems.default'));
    $workspaceId = '22222222-2222-4222-8222-222222222222';

    $file = 'tmp/media/'.$workspaceId.'/'.str_repeat('a', 8).'-'.str_repeat('b', 4).'-'.str_repeat('c', 4).'-'.str_repeat('d', 4).'-'.str_repeat('e', 12).'.mp4';
    $disk->put($file, 'x');
    touch($disk->path($file), Carbon::now()->subHours(25)->getTimestamp());

    $this->artisan('media:prune-uploads')
        ->expectsOutput('Pruned 1 abandoned upload file(s).')
        ->assertExitCode(0);
});
