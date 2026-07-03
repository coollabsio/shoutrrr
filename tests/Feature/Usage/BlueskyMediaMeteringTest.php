<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('records a media_upload event when Bluesky publishes a post with an image', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->bluesky()->for($workspace)->create(['remote_account_id' => 'did:plc:me']);
    $target = PostTarget::factory()->for($account, 'account')->create(['platform' => Platform::Bluesky->value]);

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
        'alt_text' => 'a cat',
    ]);

    Http::fake([
        '*com.atproto.repo.uploadBlob' => Http::response([
            'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafblob'], 'mimeType' => 'image/jpeg', 'size' => 11],
        ]),
        '*com.atproto.repo.createRecord' => Http::response(['uri' => 'at://r/1', 'cid' => 'cid1']),
    ]);

    $context = new PublishContext(
        target: $target,
        segments: ['look at this'],
        media: [$media],
        account: $account,
        credentials: ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']],
    );

    app(BlueskyPublishConnector::class)->publish($context);

    $event = UsageEvent::where('operation', 'media_upload')->firstOrFail();
    expect($event->platform)->toBe('bluesky')
        ->and($event->workspace_id)->toBe($workspace->id)
        ->and($event->succeeded)->toBeTrue();
});
