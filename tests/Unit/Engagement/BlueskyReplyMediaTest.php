<?php

// tests/Unit/Engagement/BlueskyReplyMediaTest.php
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTargetReply;
use App\Services\Atproto\DPoP;
use App\Services\Engagement\Connectors\BlueskyEngagementConnector;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function bskyConnector(): BlueskyEngagementConnector
{
    return new BlueskyEngagementConnector(app(Factory::class), app(DPoP::class));
}

function bskyCreds(): array
{
    return ['session' => ['pds' => 'https://bsky.social', 'accessJwt' => 'jwt']];
}

beforeEach(function () {
    Storage::fake('public');
});

test('postReply uploads image blobs and attaches an images embed', function () {
    Http::fake([
        '*com.atproto.repo.getRecord*' => Http::response(['value' => []]),
        '*com.atproto.repo.uploadBlob*' => Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'cid1']]]),
        '*com.atproto.repo.createRecord*' => Http::response(['uri' => 'at://mine', 'cid' => 'cidmine']),
    ]);

    $path = Storage::disk('public')->putFileAs('media/ws', UploadedFile::fake()->image('x.jpg'), 'x.jpg');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => $path, 'kind' => 'image', 'mime' => 'image/jpeg', 'alt_text' => 'cat']);
    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);
    $parent = PostTargetReply::factory()->create(['remote_reply_id' => 'at://did:plc:them/app.bsky.feed.post/abc', 'remote_cid' => 'c']);

    $result = bskyConnector()->postReply($account, $parent, 'hi', bskyCreds(), [$media]);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'uploadBlob'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'createRecord')
        && ($r['record']['embed']['$type'] ?? '') === 'app.bsky.embed.images');
});

test('postReply uploads a video and attaches a video embed once the job completes', function () {
    Http::fake([
        '*com.atproto.repo.getRecord*' => Http::response(['value' => []]),
        '*com.atproto.server.getServiceAuth*' => Http::response(['token' => 'svc']),
        '*app.bsky.video.uploadVideo*' => Http::response(['jobId' => 'job1']),
        '*app.bsky.video.getJobStatus*' => Http::response(['jobStatus' => ['state' => 'JOB_STATE_COMPLETED', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'vidcid']]]]),
        '*com.atproto.repo.createRecord*' => Http::response(['uri' => 'at://mine', 'cid' => 'cidmine']),
    ]);

    $path = Storage::disk('public')->put('media/ws/v.mp4', 'fake-bytes');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4', 'kind' => 'video', 'mime' => 'video/mp4']);
    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);
    $parent = PostTargetReply::factory()->create(['remote_reply_id' => 'at://did:plc:them/app.bsky.feed.post/abc', 'remote_cid' => 'c']);

    $result = bskyConnector()->postReply($account, $parent, 'clip', bskyCreds(), [$media]);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), 'createRecord')
        && ($r['record']['embed']['$type'] ?? '') === 'app.bsky.embed.video');
});
