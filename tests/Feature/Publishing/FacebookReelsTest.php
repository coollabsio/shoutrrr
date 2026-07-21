<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\FacebookConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

/**
 * @param  array<string, mixed>  $targetOverrides
 */
function fbReelsContext(PostMedia $media, array $targetOverrides = []): PublishContext
{
    $target = PostTarget::factory()->create(array_merge([
        'platform' => Platform::Facebook->value,
        'format' => PostFormat::Reels,
    ], $targetOverrides));

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Facebook->value,
        'remote_account_id' => 'page123',
    ]);

    return new PublishContext(
        target: $target,
        segments: ['my reel'],
        media: [$media],
        account: $account,
        credentials: ['access_token' => 'page-tok'],
    );
}

function fbReelsVideo(): PostMedia
{
    $bytes = str_repeat('x', 30);
    Storage::disk('public')->put('media/reel.mp4', $bytes);

    return PostMedia::factory()->video()->create([
        'disk' => 'public',
        'path' => 'media/reel.mp4',
        'mime' => 'video/mp4',
    ]);
}

test('a reel drives the video_reels start, upload, and finish phases with the description', function () {
    Http::fake([
        'https://graph.facebook.com/*/video_reels' => Http::sequence()
            ->push(['video_id' => 'v-1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v-1'])
            ->push(['success' => true]),
        'https://rupload.facebook.com/*' => Http::response(['success' => true]),
    ]);

    $result = app(FacebookConnector::class)->publish(fbReelsContext(fbReelsVideo()));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['v-1']);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/video_reels')
        && ($r->data()['upload_phase'] ?? null) === 'start');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'rupload.facebook.com')
        && $r->hasHeader('Authorization', 'OAuth page-tok')
        && $r->header('offset')[0] === '0'
        && $r->header('file_size')[0] === '30');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/video_reels')
        && ($r->data()['upload_phase'] ?? null) === 'finish'
        && ($r->data()['video_id'] ?? null) === 'v-1'
        && ($r->data()['description'] ?? null) === 'my reel'
        && ($r->data()['video_state'] ?? null) === 'PUBLISHED');
});

test('a reel finish response with success false maps to a server error', function () {
    Http::fake([
        'https://graph.facebook.com/*/video_reels' => Http::sequence()
            ->push(['video_id' => 'v-1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v-1'])
            ->push(['success' => false]),
        'https://rupload.facebook.com/*' => Http::response(['success' => true]),
    ]);

    $result = app(FacebookConnector::class)->publish(fbReelsContext(fbReelsVideo()));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::ServerError);
});

test('a reel start response missing the video id or upload url fails without persisting state', function () {
    Http::fake([
        'https://graph.facebook.com/*/video_reels' => Http::response(['success' => true]),
    ]);

    $context = fbReelsContext(fbReelsVideo());
    $result = app(FacebookConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::ServerError);

    // No upload attempted, and no bogus resumable state left for a retry to trip on.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'rupload.facebook.com'));
    expect($context->target->fresh()->media_upload_state)->toBeNull();
});

test('a reel without a video fails validation before any http call', function () {
    Http::fake();

    $target = PostTarget::factory()->create([
        'platform' => Platform::Facebook->value,
        'format' => PostFormat::Reels,
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Facebook->value,
        'remote_account_id' => 'page123',
    ]);

    $result = app(FacebookConnector::class)->publish(new PublishContext(
        target: $target,
        segments: ['no video here'],
        media: [],
        account: $account,
        credentials: ['access_token' => 'page-tok'],
    ));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation);

    Http::assertNothingSent();
});

test('a reel resume skips the start phase when a video id and upload url are already persisted', function () {
    $media = fbReelsVideo();

    $context = fbReelsContext($media, [
        'media_upload_state' => [
            $media->id => [
                'remote_ref' => 'v-1',
                'state' => 'processing',
                'blob' => ['upload_url' => 'https://rupload.facebook.com/video-upload/v-1'],
            ],
        ],
    ]);

    Http::fake([
        'https://rupload.facebook.com/*' => Http::response(['success' => true]),
        'https://graph.facebook.com/*/video_reels' => Http::response(['success' => true]),
    ]);

    $result = app(FacebookConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['v-1']);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/video_reels')
        && ($r->data()['upload_phase'] ?? null) === 'start');

    Http::assertSentCount(2);
});
