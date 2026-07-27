<?php

use App\Models\PostMedia;
use App\Models\Workspace;
use App\Services\Gifs\GifAttacher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

function clipVariant(string $url, int $bytes): array
{
    return ['url' => $url, 'mime' => 'video/mp4', 'width' => 480, 'height' => 270, 'bytes' => $bytes];
}

function fakeClipBytes(): string
{
    return "\x00\x00\x00\x20".'ftypisom'.str_repeat("\x00", 4096);
}

beforeEach(function (): void {
    Storage::fake('local');
    // static.klipy.com is used instead of the spec's cdn.klipy.com: the latter is
    // NXDOMAIN in real DNS, and SafeVideoFetcher resolves the host for real before
    // Http::fake ever intercepts the request (same reasoning as GifAttacherTest).
    Http::fake(['https://static.klipy.com/*' => Http::response(fakeClipBytes(), 200, ['Content-Type' => 'video/mp4'])]);
});

test('stores a clip as video media with its duration', function () {
    $workspace = Workspace::factory()->create();

    $media = app(GifAttacher::class)->attach(
        $workspace->id, 'clip', 'Slow clap',
        [clipVariant('https://static.klipy.com/clip.mp4', 900_000)], [], 6,
    );

    expect($media->kind)->toBe('video')
        ->and($media->mime)->toBe('video/mp4')
        ->and($media->duration_seconds)->toBe(6)
        ->and($media->width)->toBe(480)
        ->and($media->height)->toBe(270)
        ->and($media->alt_text)->toBe('Slow clap');
});

test('rejects a clip with no derivable duration', function () {
    $workspace = Workspace::factory()->create();

    expect(fn () => app(GifAttacher::class)->attach(
        $workspace->id, 'clip', 'Mystery',
        [clipVariant('https://static.klipy.com/clip.mp4', 900_000)], [], null,
    ))->toThrow(RuntimeException::class, 'duration');
})->skip(fn (): bool => (new ExecutableFinder)->find('ffprobe') !== null,
    'ffprobe is installed, so the duration is derivable');

test('rejects a clip when media is already attached', function () {
    $workspace = Workspace::factory()->create();
    $existing = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'kind' => 'image', 'mime' => 'image/png']);

    expect(fn () => app(GifAttacher::class)->attach(
        $workspace->id, 'clip', 'Slow clap',
        [clipVariant('https://static.klipy.com/clip.mp4', 900_000)], [$existing], 6,
    ))->toThrow(RuntimeException::class, 'only attachment');
});

test('rejects a clip url off the klipy cdn', function () {
    $workspace = Workspace::factory()->create();

    expect(fn () => app(GifAttacher::class)->attach(
        $workspace->id, 'clip', 'Evil',
        [clipVariant('https://evil.example.com/x.mp4', 900_000)], [], 6,
    ))->toThrow(RuntimeException::class, 'not a supported GIF source');
});
