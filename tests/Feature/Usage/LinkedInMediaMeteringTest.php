<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Services\Publishing\Connectors\LinkedInConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('records a media_upload event when LinkedIn publishes a post with an image', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    Storage::fake('public');
    Storage::disk('public')->put('media/pic.png', 'png-bytes');

    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::LinkedIn->value,
        'remote_account_id' => 'person123',
    ]);
    $target = PostTarget::factory()->for($account, 'account')->create(['platform' => Platform::LinkedIn->value]);

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/pic.png',
        'mime' => 'image/png',
        'alt_text' => 'a picture',
    ]);

    Http::fake([
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response([
            'value' => ['uploadUrl' => 'https://upload.linkedin.com/put/abc', 'image' => 'urn:li:image:42'],
        ]),
        'https://upload.linkedin.com/put/abc' => Http::response('', 201),
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:7']),
    ]);

    $context = new PublishContext(
        target: $target,
        segments: ['look'],
        media: [$media],
        account: $account,
        credentials: ['access_token' => 'tok'],
    );

    app(LinkedInConnector::class)->publish($context);

    $event = UsageEvent::where('operation', 'media_upload')->firstOrFail();
    expect($event->platform)->toBe('linkedin')
        ->and($event->workspace_id)->toBe($workspace->id)
        ->and($event->succeeded)->toBeTrue();
});

it('records media_upload events when a text+link post uploads an article thumbnail', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $workspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->for($workspace)->create([
        'platform' => Platform::LinkedIn->value,
        'remote_account_id' => 'person123',
    ]);
    $target = PostTarget::factory()->for($account, 'account')->create(['platform' => Platform::LinkedIn->value]);

    Http::fake([
        // The article page carrying an og:image.
        'https://example.com/article' => Http::response(
            '<html><head><meta property="og:title" content="Title"><meta property="og:image" content="https://example.com/thumb.jpg"></head></html>',
        ),
        // The thumbnail image bytes.
        'https://example.com/thumb.jpg' => Http::response('jpg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        // The register + upload endpoints (same as uploadImages()).
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response([
            'value' => ['uploadUrl' => 'https://upload.linkedin.com/put/thumb', 'image' => 'urn:li:image:99'],
        ]),
        'https://upload.linkedin.com/put/thumb' => Http::response('', 201),
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:8']),
    ]);

    $context = new PublishContext(
        target: $target,
        segments: ['read this https://example.com/article'],
        media: [],
        account: $account,
        credentials: ['access_token' => 'tok'],
    );

    app(LinkedInConnector::class)->publish($context);

    $events = UsageEvent::where('operation', 'media_upload')->get();
    // The register call + the PUT upload are both metered.
    expect($events)->toHaveCount(2)
        ->and($events->every(fn ($e): bool => $e->platform === 'linkedin' && $e->workspace_id === $workspace->id))->toBeTrue();
});
