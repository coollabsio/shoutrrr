<?php

declare(strict_types=1);

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\XConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

function xVideoContext(PostTarget $target): PublishContext
{
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));

    return new PublishContext(
        target: $target,
        segments: ['hello'],
        media: [$media],
        account: $target->account()->firstOrFail(),
        credentials: ['access_token' => 'tok'],
    );
}

test('still-processing video returns MediaProcessing and persists the media id', function (): void {
    $target = PostTarget::factory()->for(ConnectedAccount::factory()->state(['platform' => 'x']), 'account')->create();

    Http::fake([
        'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => '99']], 200),
        'api.x.com/2/media/upload/99/append' => Http::response([], 200),
        'api.x.com/2/media/upload/99/finalize' => Http::response(['data' => ['processing_info' => ['state' => 'in_progress', 'check_after_secs' => 5]]], 200),
        'api.x.com/2/media/upload*' => Http::response(['data' => ['processing_info' => ['state' => 'in_progress', 'check_after_secs' => 5]]], 200),
    ]);

    $result = app(XConnector::class)->publish(xVideoContext($target));

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($result->retryAfter)->toBe(5);

    $state = $target->fresh()->media_upload_state;
    expect($state)->not->toBeNull()->and(array_key_first($state))->not->toBeNull();
    $entry = $state[array_key_first($state)];
    expect($entry['remote_ref'])->toBe('99')
        ->and($entry['state'])->toBe('processing');
});

test('resume skips upload and attaches once succeeded', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'x'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));

    $target = PostTarget::factory()->for($account, 'account')->create([
        'media_upload_state' => [$media->id => ['remote_ref' => '99', 'state' => 'processing']],
    ]);

    Http::fake([
        'api.x.com/2/media/upload*' => Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]], 200),
        'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 'tweet1']], 201),
    ]);

    $ctx = new PublishContext($target, ['hello'], [$media], $account, ['access_token' => 'tok']);
    $result = app(XConnector::class)->publish($ctx);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['tweet1']);

    // No initialize call happened on resume.
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/media/upload/initialize'));
});

test('transient 503 on status poll returns MediaProcessing (not ServerError)', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'x'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));

    $target = PostTarget::factory()->for($account, 'account')->create([
        'media_upload_state' => [$media->id => ['remote_ref' => '99', 'state' => 'processing']],
    ]);

    Http::fake([
        'api.x.com/2/media/upload*' => Http::response(['error' => 'Service Unavailable'], 503),
    ]);

    $ctx = new PublishContext($target, ['hello'], [$media], $account, ['access_token' => 'tok']);
    $result = app(XConnector::class)->publish($ctx);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::MediaProcessing);
});

test('STATUS=failed returns a terminal ServerError', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'x'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));

    $target = PostTarget::factory()->for($account, 'account')->create([
        'media_upload_state' => [$media->id => ['remote_ref' => '99', 'state' => 'processing']],
    ]);

    Http::fake([
        'api.x.com/2/media/upload*' => Http::response(['data' => ['processing_info' => ['state' => 'failed']]], 200),
    ]);

    $ctx = new PublishContext($target, ['hello'], [$media], $account, ['access_token' => 'tok']);
    $result = app(XConnector::class)->publish($ctx);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::ServerError);
});
