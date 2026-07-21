<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Support\FileStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// The streaming receiver only handles local disks; pin the default to `public`
// (a local driver) so FileStorage returns a signed route to it.
beforeEach(fn () => config(['filesystems.default' => 'public']));

function signedStreamUrl(string $key): string
{
    return FileStorage::temporaryVideoUploadUrl($key, now()->addMinutes(15))['url'];
}

// Minimal ISO-BMFF header so the stored bytes read back as a plausible MP4.
function streamBody(int $pad = 256): string
{
    return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2".str_repeat('x', $pad);
}

test('a signed upload streams the body to disk and returns 204', function (): void {
    $disk = config('filesystems.default');
    Storage::fake($disk);

    $key = 'tmp/media/'.Str::uuid().'/'.Str::uuid().'.mp4';
    $body = streamBody();

    test()->call('PUT', signedStreamUrl($key), [], [], [], [
        'CONTENT_LENGTH' => (string) strlen($body),
    ], $body)->assertNoContent();

    expect(Storage::disk($disk)->exists($key))->toBeTrue()
        ->and(Storage::disk($disk)->get($key))->toBe($body);
});

test('an upload declaring a size over the ceiling is rejected with 413', function (): void {
    $disk = config('filesystems.default');
    Storage::fake($disk);

    $key = 'tmp/media/'.Str::uuid().'/'.Str::uuid().'.mp4';

    // A small real body, but a Content-Length that claims to exceed the ceiling:
    // the handler must reject before writing anything.
    test()->call('PUT', signedStreamUrl($key), [], [], [], [
        'CONTENT_LENGTH' => (string) (Platform::maxVideoBytesCeiling() + 1),
    ], streamBody())->assertStatus(413);

    expect(Storage::disk($disk)->exists($key))->toBeFalse();
});

test('a tampered path invalidates the signature and is rejected with 403', function (): void {
    $disk = config('filesystems.default');
    Storage::fake($disk);

    $key = 'tmp/media/'.Str::uuid().'/'.Str::uuid().'.mp4';
    // Sign one key, then point the PUT at a different key: the signature no longer
    // matches the path, so the `signed` middleware must reject it.
    $tampered = str_replace($key, 'tmp/media/'.Str::uuid().'/'.Str::uuid().'.mp4', signedStreamUrl($key));

    test()->call('PUT', $tampered, [], [], [], [], streamBody())->assertStatus(403);
});

test('an unsigned request is rejected with 403', function (): void {
    Storage::fake(config('filesystems.default'));

    test()->call('PUT', '/uploads/stream/tmp/media/'.Str::uuid().'/'.Str::uuid().'.mp4', [], [], [], [], streamBody())
        ->assertStatus(403);
});
