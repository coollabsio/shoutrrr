<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\InstagramConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<PostMedia>  $media
 * @param  array<string, mixed>  $targetOverrides
 */
function igFormatContext(array $segments, array $media, PostFormat $format, array $targetOverrides = []): PublishContext
{
    $target = PostTarget::factory()->create(array_merge([
        'platform' => Platform::Instagram->value,
        'format' => $format->value,
    ], $targetOverrides));
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

test('a story container sends media_type STORIES and no caption', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/story.jpg', 'jpg-bytes');

    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/story.jpg', 'mime' => 'image/jpeg']);

    Http::fake([
        'https://graph.facebook.com/*/ig123/media' => Http::response(['id' => 'container-1']),
        'https://graph.facebook.com/*/container-1*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.facebook.com/*/ig123/media_publish' => Http::response(['id' => 'ig-1']),
    ]);

    $result = app(InstagramConnector::class)->publish(igFormatContext(['hello story'], [$media], PostFormat::Story));

    expect($result->isSuccessful())->toBeTrue();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/ig123/media') || str_contains($request->url(), 'media_publish')) {
            return false;
        }

        $data = $request->data();

        return ($data['media_type'] ?? null) === 'STORIES'
            && ! array_key_exists('caption', $data)
            && array_key_exists('image_url', $data);
    });
});

test('a reels container keeps the caption and sends media_type REELS', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/clip.mp4', 'mp4-bytes');

    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/clip.mp4']);

    Http::fake([
        'https://graph.facebook.com/*/ig123/media' => Http::response(['id' => 'container-2']),
        'https://graph.facebook.com/*/container-2*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.facebook.com/*/ig123/media_publish' => Http::response(['id' => 'ig-2']),
    ]);

    $result = app(InstagramConnector::class)->publish(igFormatContext(['reel caption'], [$media], PostFormat::Reels));

    expect($result->isSuccessful())->toBeTrue();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/ig123/media') || str_contains($request->url(), 'media_publish')) {
            return false;
        }

        $data = $request->data();

        return ($data['media_type'] ?? null) === 'REELS'
            && ($data['caption'] ?? '') === 'reel caption'
            && array_key_exists('video_url', $data);
    });
});

test('a reels container with no video fails validation', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');

    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    Http::fake();

    $result = app(InstagramConnector::class)->publish(igFormatContext(['no video here'], [$media], PostFormat::Reels));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation);
});
