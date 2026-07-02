<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Publishing\Connectors\XConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('records a media_upload event when X publishes a post with an image', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::X->value]);
    $target = PostTarget::factory()->for($account, 'account')->create(['platform' => Platform::X->value]);

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
    ]);

    Http::fake([
        'api.x.com/2/media/upload' => Http::response(['data' => ['id' => '99001', 'media_key' => '3_99001']], 200),
        'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']], 200),
    ]);

    $context = new PublishContext(
        target: $target,
        segments: ['look at this'],
        media: [$media],
        account: $account,
        credentials: ['access_token' => 'tok'],
    );

    app(XConnector::class)->publish($context);

    $event = UsageEvent::where('operation', 'media_upload')->firstOrFail();
    expect($event->platform)->toBe('x')
        ->and($event->workspace_id)->toBe($workspace->id)
        ->and($event->succeeded)->toBeTrue();
});
