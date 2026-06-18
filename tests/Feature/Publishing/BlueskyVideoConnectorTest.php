<?php

declare(strict_types=1);

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

function blueskyVideoCredentials(): array
{
    return ['session' => ['pds' => 'https://morel.host.bsky.network', 'accessJwt' => 'jwt']];
}

test('in-progress job returns MediaProcessing and persists the jobId', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'bluesky', 'remote_account_id' => 'did:plc:abc'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));
    $target = PostTarget::factory()->for($account, 'account')->create();

    Http::fake([
        '*/xrpc/com.atproto.server.getServiceAuth*' => Http::response(['token' => 'svc'], 200),
        'video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(['jobId' => 'job-1'], 200),
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response(['jobStatus' => ['state' => 'JOB_STATE_RUNNING', 'progress' => 40]], 200),
    ]);

    $ctx = new PublishContext($target, ['hi'], [$media], $account, blueskyVideoCredentials());
    $result = app(BlueskyPublishConnector::class)->publish($ctx);

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($target->fresh()->media_upload_state[$media->id]['remote_ref'])->toBe('job-1');
});

test('completed job embeds the blob and posts on resume', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'bluesky', 'remote_account_id' => 'did:plc:abc'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));
    $target = PostTarget::factory()->for($account, 'account')->create([
        'media_upload_state' => [$media->id => ['remote_ref' => 'job-1', 'state' => 'processing']],
    ]);

    Http::fake([
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response([
            'jobStatus' => ['state' => 'JOB_STATE_COMPLETED', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'cid']]],
        ], 200),
        '*/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://did:plc:abc/app.bsky.feed.post/1', 'cid' => 'cid1'], 200),
    ]);

    $ctx = new PublishContext($target, ['hi'], [$media], $account, blueskyVideoCredentials());
    $result = app(BlueskyPublishConnector::class)->publish($ctx);

    expect($result->isSuccessful())->toBeTrue();
    Http::assertSent(fn ($req) => str_contains($req->url(), 'createRecord')
        && data_get($req->data(), 'record.embed.$type') === 'app.bsky.embed.video');
});

test('transient 503 on job-status poll returns MediaProcessing (not ServerError)', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'bluesky', 'remote_account_id' => 'did:plc:abc'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));
    $target = PostTarget::factory()->for($account, 'account')->create([
        'media_upload_state' => [$media->id => ['remote_ref' => 'job-1', 'state' => 'processing']],
    ]);

    Http::fake([
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response(['error' => 'Service Unavailable'], 503),
    ]);

    $ctx = new PublishContext($target, ['hi'], [$media], $account, blueskyVideoCredentials());
    $result = app(BlueskyPublishConnector::class)->publish($ctx);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::MediaProcessing);
});

test('failed job returns terminal ServerError', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'bluesky', 'remote_account_id' => 'did:plc:abc'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));
    $target = PostTarget::factory()->for($account, 'account')->create([
        'media_upload_state' => [$media->id => ['remote_ref' => 'job-1', 'state' => 'processing']],
    ]);

    Http::fake([
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response([
            'jobStatus' => ['state' => 'JOB_STATE_FAILED', 'error' => 'Unsupported codec'],
        ], 200),
    ]);

    $ctx = new PublishContext($target, ['hi'], [$media], $account, blueskyVideoCredentials());
    $result = app(BlueskyPublishConnector::class)->publish($ctx);

    expect($result->errorKind)->toBe(ErrorKind::ServerError);
});

test('getServiceAuth is called with PDS DID and uploadBlob lxm', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'bluesky', 'remote_account_id' => 'did:plc:abc'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));
    $target = PostTarget::factory()->for($account, 'account')->create();

    Http::fake([
        '*/xrpc/com.atproto.server.getServiceAuth*' => Http::response(['token' => 'svc'], 200),
        'video.bsky.app/xrpc/app.bsky.video.uploadVideo*' => Http::response(['jobId' => 'job-2'], 200),
        'video.bsky.app/xrpc/app.bsky.video.getJobStatus*' => Http::response(['jobStatus' => ['state' => 'JOB_STATE_RUNNING', 'progress' => 10]], 200),
    ]);

    $ctx = new PublishContext($target, ['hi'], [$media], $account, blueskyVideoCredentials());
    app(BlueskyPublishConnector::class)->publish($ctx);

    Http::assertSent(function ($req): bool {
        if (! str_contains($req->url(), 'getServiceAuth')) {
            return false;
        }

        return ($req['aud'] ?? null) === 'did:web:morel.host.bsky.network'
            && ($req['lxm'] ?? null) === 'com.atproto.repo.uploadBlob';
    });
});
