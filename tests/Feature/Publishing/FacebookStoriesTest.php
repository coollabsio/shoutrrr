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
function fbStoryContext(PostMedia $media, array $targetOverrides = []): PublishContext
{
    $target = PostTarget::factory()->create(array_merge([
        'platform' => Platform::Facebook->value,
        'format' => PostFormat::Story,
    ], $targetOverrides));

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Facebook->value,
        'remote_account_id' => 'page123',
    ]);

    return new PublishContext(
        target: $target,
        segments: ['ignored caption'],
        media: [$media],
        account: $account,
        credentials: ['access_token' => 'page-tok'],
    );
}

function fbStoryImage(): PostMedia
{
    Storage::disk('public')->put('media/story.jpg', 'jpg-bytes');

    return PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/story.jpg',
        'mime' => 'image/jpeg',
    ]);
}

function fbStoryVideo(): PostMedia
{
    $bytes = str_repeat('x', 30);
    Storage::disk('public')->put('media/story.mp4', $bytes);

    return PostMedia::factory()->video()->create([
        'disk' => 'public',
        'path' => 'media/story.mp4',
        'mime' => 'video/mp4',
    ]);
}

test('a photo story uploads the photo unpublished then creates the story with no text', function () {
    Http::fake([
        'https://graph.facebook.com/*/photos*' => Http::response(['id' => 'photo-1']),
        'https://graph.facebook.com/*/photo_stories' => Http::response(['post_id' => 'story-1', 'success' => true]),
    ]);

    $result = app(FacebookConnector::class)->publish(fbStoryContext(fbStoryImage()));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['story-1']);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/photos')
        && str_contains($r->url(), 'published=false'));

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), '/photo_stories')) {
            return false;
        }

        $data = $r->data();

        return ($data['photo_id'] ?? null) === 'photo-1'
            && ! array_key_exists('message', $data)
            && ! array_key_exists('description', $data)
            && ! array_key_exists('caption', $data);
    });
});

test('a photo story finish response with success false maps to a server error', function () {
    Http::fake([
        'https://graph.facebook.com/*/photos*' => Http::response(['id' => 'photo-1']),
        'https://graph.facebook.com/*/photo_stories' => Http::response(['success' => false]),
    ]);

    $result = app(FacebookConnector::class)->publish(fbStoryContext(fbStoryImage()));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::ServerError);
});

test('a video story drives the video_stories start, upload, and finish phases', function () {
    Http::fake([
        'https://graph.facebook.com/*/video_stories' => Http::sequence()
            ->push(['video_id' => 'v-9', 'upload_url' => 'https://rupload.facebook.com/video-upload/v-9'])
            ->push(['success' => true]),
        'https://rupload.facebook.com/*' => Http::response(['success' => true]),
    ]);

    $result = app(FacebookConnector::class)->publish(fbStoryContext(fbStoryVideo()));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['v-9']);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/video_stories')
        && ($r->data()['upload_phase'] ?? null) === 'start');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'rupload.facebook.com')
        && $r->hasHeader('Authorization', 'OAuth page-tok')
        && $r->header('offset')[0] === '0'
        && $r->header('file_size')[0] === '30');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/video_stories')
        && ($r->data()['upload_phase'] ?? null) === 'finish'
        && ($r->data()['video_id'] ?? null) === 'v-9'
        && ! array_key_exists('description', $r->data()));
});

test('a video story finish response without a success flag maps to a server error', function () {
    Http::fake([
        'https://graph.facebook.com/*/video_stories' => Http::sequence()
            ->push(['video_id' => 'v-9', 'upload_url' => 'https://rupload.facebook.com/video-upload/v-9'])
            ->push(['post_id' => 'story-1']),
        'https://rupload.facebook.com/*' => Http::response(['success' => true]),
    ]);

    $result = app(FacebookConnector::class)->publish(fbStoryContext(fbStoryVideo()));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::ServerError);
});

test('a video story start response missing the video id or upload url fails without persisting state', function () {
    Http::fake([
        'https://graph.facebook.com/*/video_stories' => Http::response(['success' => true]),
    ]);

    $context = fbStoryContext(fbStoryVideo());
    $result = app(FacebookConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::ServerError);

    // No upload attempted, and no bogus resumable state left for a retry to trip on.
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'rupload.facebook.com'));
    expect($context->target->fresh()->media_upload_state)->toBeNull();
});

test('a story without media fails validation before any http call', function () {
    Http::fake();

    $target = PostTarget::factory()->create([
        'platform' => Platform::Facebook->value,
        'format' => PostFormat::Story,
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Facebook->value,
        'remote_account_id' => 'page123',
    ]);

    $result = app(FacebookConnector::class)->publish(new PublishContext(
        target: $target,
        segments: ['no media here'],
        media: [],
        account: $account,
        credentials: ['access_token' => 'page-tok'],
    ));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation);

    Http::assertNothingSent();
});
