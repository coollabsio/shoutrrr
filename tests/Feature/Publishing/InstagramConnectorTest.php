<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\InstagramConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** A real, decodable 2x2 PNG so the JPEG conversion path has actual bytes to work on. */
function pngBytes(): string
{
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * @param  list<PostMedia>  $media
 * @param  array<string, mixed>  $targetOverrides
 */
function igContext(array $segments, array $media = [], array $targetOverrides = []): PublishContext
{
    $target = PostTarget::factory()->create(array_merge(['platform' => Platform::Instagram->value], $targetOverrides));
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Instagram->value,
        'remote_account_id' => 'ig123',
    ]);

    return new PublishContext(
        target: $target,
        segments: $segments,
        media: $media,
        account: $account,
        credentials: ['access_token' => 'page-tok'],
    );
}

test('instagram fails validation with no media and makes no http calls', function () {
    Http::fake();

    $result = app(InstagramConnector::class)->publish(igContext(['hello world']));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation);

    Http::assertNothingSent();
});

test('instagram publishes a single image through the container flow', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');

    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    Http::fake([
        'https://graph.facebook.com/*/ig123/media' => Http::response(['id' => 'container-1']),
        'https://graph.facebook.com/*/container-1*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.facebook.com/*/ig123/media_publish' => Http::response(['id' => 'media-999']),
    ]);

    $result = app(InstagramConnector::class)->publish(igContext(['look at this'], [$media]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['media-999']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/ig123/media')
        && ! str_contains($request->url(), 'media_publish')
        && str_contains((string) $request['image_url'], 'pic.jpg')
        && $request['caption'] === 'look at this');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/ig123/media_publish')
        && $request['creation_id'] === 'container-1');
});

test('instagram converts a non-jpeg image and hands Meta the derived jpeg url', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.png', pngBytes());

    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.png', 'mime' => 'image/png']);

    Http::fake([
        'https://graph.facebook.com/*/ig123/media' => Http::response(['id' => 'container-png']),
        'https://graph.facebook.com/*/container-png*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.facebook.com/*/ig123/media_publish' => Http::response(['id' => 'media-png']),
    ]);

    $result = app(InstagramConnector::class)->publish(igContext(['a png'], [$media]));

    expect($result->isSuccessful())->toBeTrue();

    // Instagram accepts JPEG only (Platform::Instagram->allowedMime()); a .png url is
    // rejected by Meta with "Only image and video media type is allowed".
    Http::assertSent(fn ($request) => str_contains($request->url(), '/ig123/media')
        && ! str_contains($request->url(), 'media_publish')
        && ! str_contains((string) $request['image_url'], '.png')
        && str_contains((string) $request['image_url'], '.jpg'));
});

test('instagram returns a MediaProcessing failure and persists the container id while the status is IN_PROGRESS', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');

    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    Http::fake([
        'https://graph.facebook.com/*/ig123/media' => Http::response(['id' => 'container-2']),
        'https://graph.facebook.com/*/container-2*' => Http::response(['status_code' => 'IN_PROGRESS']),
    ]);

    $context = igContext(['processing'], [$media]);
    $result = app(InstagramConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($result->retryAfter)->toBe(6);

    $state = $context->target->fresh()->media_upload_state;
    expect($state['container']['remote_ref'])->toBe('container-2');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'media_publish'));
});

test('instagram resumes from a persisted container id, skipping re-creation', function () {
    Http::fake([
        'https://graph.facebook.com/*/container-existing*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.facebook.com/*/ig123/media_publish' => Http::response(['id' => 'media-777']),
    ]);

    $result = app(InstagramConnector::class)->publish(igContext(
        ['resuming'],
        [PostMedia::factory()->create()],
        ['media_upload_state' => ['container' => ['remote_ref' => 'container-existing', 'state' => 'processing']]],
    ));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['media-777']);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ig123/media')
        && ! str_contains($request->url(), 'media_publish'));

    Http::assertSentCount(2);
});

test('instagram builds a carousel from two images then publishes the parent container', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/a.jpg', 'a-bytes');
    Storage::disk('public')->put('media/b.jpg', 'b-bytes');

    $first = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/a.jpg', 'mime' => 'image/jpeg']);
    $second = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/b.jpg', 'mime' => 'image/jpeg']);

    Http::fake([
        'https://graph.facebook.com/*/ig123/media' => Http::sequence()
            ->push(['id' => 'child-1'])
            ->push(['id' => 'child-2'])
            ->push(['id' => 'parent-1']),
        'https://graph.facebook.com/*/parent-1*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.facebook.com/*/ig123/media_publish' => Http::response(['id' => 'media-carousel']),
    ]);

    $result = app(InstagramConnector::class)->publish(igContext(['carousel caption'], [$first, $second]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['media-carousel']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/ig123/media')
        && ! str_contains($request->url(), 'media_publish')
        && ($request['is_carousel_item'] ?? null) === 'true'
        && str_contains((string) ($request['image_url'] ?? ''), 'a.jpg'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/ig123/media')
        && ! str_contains($request->url(), 'media_publish')
        && ($request['is_carousel_item'] ?? null) === 'true'
        && str_contains((string) ($request['image_url'] ?? ''), 'b.jpg'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/ig123/media')
        && ! str_contains($request->url(), 'media_publish')
        && ($request['media_type'] ?? null) === 'CAROUSEL'
        && ($request['children'] ?? null) === 'child-1,child-2'
        && ($request['caption'] ?? null) === 'carousel caption');
});

test('instagram publishes a video as a REELS container', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/clip.mp4', 'mp4-bytes');

    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/clip.mp4']);

    Http::fake([
        'https://graph.facebook.com/*/ig123/media' => Http::response(['id' => 'reel-container']),
        'https://graph.facebook.com/*/reel-container*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.facebook.com/*/ig123/media_publish' => Http::response(['id' => 'reel-media']),
    ]);

    $result = app(InstagramConnector::class)->publish(igContext(['reel time'], [$media]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['reel-media']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/ig123/media')
        && ! str_contains($request->url(), 'media_publish')
        && $request['media_type'] === 'REELS'
        && str_contains((string) $request['video_url'], 'clip.mp4'));
});

test('instagram maps a 401 to AuthExpired', function () {
    Http::fake([
        'https://graph.facebook.com/*/ig123/media' => Http::response(['error' => ['message' => 'expired']], 401),
    ]);

    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    $result = app(InstagramConnector::class)->publish(igContext(['hi'], [$media]));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::AuthExpired);
});

test('instagram resume guard returns success without making any http calls', function () {
    Http::fake();

    $result = app(InstagramConnector::class)->publish(igContext(['hi'], [], [
        'remote_id' => 'media-555',
        'remote_ids' => ['media-555'],
    ]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['media-555']);

    Http::assertNothingSent();
});
