<?php

use App\Models\PostMedia;
use App\Models\Workspace;
use App\Services\Gifs\GifAttacher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function gifVariant(string $url, int $bytes, string $mime = 'image/gif'): array
{
    return ['url' => $url, 'mime' => $mime, 'width' => 320, 'height' => 240, 'bytes' => $bytes];
}

/** A 1x1 transparent GIF. */
function tinyGifBytes(): string
{
    return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}

beforeEach(function (): void {
    Storage::fake('local');
    // static.klipy.com is used instead of the spec's cdn.klipy.com: the latter is
    // NXDOMAIN in real DNS (checked against 8.8.8.8 and 1.1.1.1), and
    // SafeImageFetcher resolves the host for real before Http::fake ever
    // intercepts the request. static.klipy.com resolves to a real public
    // Cloudflare IP, keeps the same klipy.com suffix the allow-list checks, and
    // there is no wildcard DNS on the domain (random subdomains stay NXDOMAIN).
    Http::fake(['https://static.klipy.com/*' => Http::response(tinyGifBytes(), 200, ['Content-Type' => 'image/gif'])]);
});

test('stores the largest variant that fits under the image cap', function () {
    $workspace = Workspace::factory()->create();

    $media = app(GifAttacher::class)->attach(
        $workspace->id,
        'gif',
        'Happy dance',
        [
            gifVariant('https://static.klipy.com/huge.gif', 20 * 1024 * 1024),
            gifVariant('https://static.klipy.com/ok.gif', 3 * 1024 * 1024),
            gifVariant('https://static.klipy.com/small.gif', 40_000),
        ],
        [],
    );

    expect($media->kind)->toBe('image')
        ->and($media->mime)->toBe('image/gif');

    Http::assertSent(fn ($request) => $request->url() === 'https://static.klipy.com/ok.gif');
});

test('uses the klipy title as alt text', function () {
    $workspace = Workspace::factory()->create();

    $media = app(GifAttacher::class)->attach(
        $workspace->id, 'gif', 'Happy dance',
        [gifVariant('https://static.klipy.com/ok.gif', 40_000)], [],
    );

    expect($media->alt_text)->toBe('Happy dance');
});

test('rejects a url that is not on the klipy cdn', function () {
    $workspace = Workspace::factory()->create();

    expect(fn () => app(GifAttacher::class)->attach(
        $workspace->id, 'gif', 'Evil',
        [gifVariant('https://evil.example.com/x.gif', 40_000)], [],
    ))->toThrow(RuntimeException::class, 'not a supported GIF source');
});

test('rejects when every variant exceeds the cap', function () {
    $workspace = Workspace::factory()->create();

    expect(fn () => app(GifAttacher::class)->attach(
        $workspace->id, 'gif', 'Chonky',
        [gifVariant('https://static.klipy.com/huge.gif', 20 * 1024 * 1024)], [],
    ))->toThrow(RuntimeException::class, 'too large');
});

test('rejects a gif when other media is already attached', function () {
    $workspace = Workspace::factory()->create();
    $existing = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'kind' => 'image', 'mime' => 'image/png']);

    expect(fn () => app(GifAttacher::class)->attach(
        $workspace->id, 'gif', 'Happy',
        [gifVariant('https://static.klipy.com/ok.gif', 40_000)], [$existing],
    ))->toThrow(RuntimeException::class, 'on its own');
});

test('allows a sticker alongside other images', function () {
    $workspace = Workspace::factory()->create();
    $existing = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'kind' => 'image', 'mime' => 'image/png']);

    $media = app(GifAttacher::class)->attach(
        $workspace->id, 'sticker', 'Star',
        [gifVariant('https://static.klipy.com/ok.gif', 40_000, 'image/gif')], [$existing],
    );

    expect($media->kind)->toBe('image');
});
