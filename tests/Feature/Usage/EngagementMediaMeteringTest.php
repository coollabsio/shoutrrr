<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTargetReply;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Engagement\Connectors\BlueskyEngagementConnector;
use App\Services\Engagement\Connectors\XEngagementConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('records a media_upload event when an X reply carries an image', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    Storage::fake('public');
    Storage::disk('public')->put('media/pic.png', 'png-bytes');

    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::X->value,
        'remote_account_id' => '123',
    ]);
    $reply = PostTargetReply::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'remote_reply_id' => '900',
    ]);
    $media = PostMedia::factory()->create([
        'disk' => 'public', 'path' => 'media/pic.png', 'mime' => 'image/png', 'kind' => 'image',
    ]);

    Http::fake([
        'api.x.com/2/media/upload' => Http::response(['data' => ['id' => 'media-1']]),
        'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 'tweet-1']], 201),
    ]);

    app(XEngagementConnector::class)->postReply($account, $reply, 'hi', ['access_token' => 'tok'], [$media]);

    $event = UsageEvent::where('operation', 'media_upload')->firstOrFail();
    expect($event->category)->toBe('external_api')
        ->and($event->platform)->toBe('x')
        ->and($event->workspace_id)->toBe($workspace->id)
        ->and($event->succeeded)->toBeTrue();
});

it('records a media_upload event when a Bluesky reply carries an image', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->bluesky()->for($workspace)->create(['remote_account_id' => 'did:plc:me']);
    $reply = PostTargetReply::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Bluesky,
        'remote_reply_id' => 'at://did:plc:parent/app.bsky.feed.post/abc',
        'remote_cid' => 'cid-parent',
    ]);
    $media = PostMedia::factory()->create([
        'disk' => 'public', 'path' => 'media/cat.jpg', 'mime' => 'image/jpeg', 'kind' => 'image', 'alt_text' => 'a cat',
    ]);

    Http::fake([
        '*com.atproto.repo.getRecord*' => Http::response(['value' => []]),
        '*com.atproto.repo.uploadBlob' => Http::response([
            'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafblob'], 'mimeType' => 'image/jpeg', 'size' => 11],
        ]),
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://r/1', 'cid' => 'cid1']),
    ]);

    app(BlueskyEngagementConnector::class)->postReply(
        $account,
        $reply,
        'nice',
        ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']],
        [$media],
    );

    $event = UsageEvent::where('operation', 'media_upload')->firstOrFail();
    expect($event->category)->toBe('external_api')
        ->and($event->platform)->toBe('bluesky')
        ->and($event->workspace_id)->toBe($workspace->id)
        ->and($event->succeeded)->toBeTrue();
});
