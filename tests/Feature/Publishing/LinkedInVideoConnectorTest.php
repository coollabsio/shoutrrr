<?php

declare(strict_types=1);

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\LinkedInConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

test('PROCESSING video returns MediaProcessing after upload', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'linkedin'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));
    $target = PostTarget::factory()->create(['connected_account_id' => $account->id]);

    Http::fake([
        'api.linkedin.com/rest/videos?action=initializeUpload' => Http::response([
            'value' => [
                'video' => 'urn:li:video:abc',
                'uploadToken' => '',
                'uploadInstructions' => [['uploadUrl' => 'https://up.linkedin.com/p1', 'firstByte' => 0, 'lastByte' => 2047]],
            ],
        ], 200),
        'up.linkedin.com/*' => Http::response('', 201, ['etag' => 'etag-1']),
        'api.linkedin.com/rest/videos?action=finalizeUpload' => Http::response([], 200),
        'api.linkedin.com/rest/videos/*' => Http::response(['status' => 'PROCESSING'], 200),
    ]);

    $ctx = new PublishContext($target, ['hi'], [$media], $account, ['access_token' => 'tok']);
    $result = app(LinkedInConnector::class)->publish($ctx);

    expect($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($target->fresh()->media_upload_state[$media->id]['remote_ref'])->toBe('urn:li:video:abc');
});

test('AVAILABLE video is referenced in the post on resume', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'linkedin'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'media_upload_state' => [$media->id => ['remote_ref' => 'urn:li:video:abc', 'state' => 'processing']],
    ]);

    Http::fake([
        'api.linkedin.com/rest/videos/*' => Http::response(['status' => 'AVAILABLE'], 200),
        'api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:1']),
    ]);

    $ctx = new PublishContext($target, ['hi'], [$media], $account, ['access_token' => 'tok']);
    $result = app(LinkedInConnector::class)->publish($ctx);

    expect($result->isSuccessful())->toBeTrue()->and($result->remoteIds)->toBe(['urn:li:share:1']);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'action=initializeUpload'));
});

test('transient 503 on status poll returns MediaProcessing (not ServerError)', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'linkedin'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'media_upload_state' => [$media->id => ['remote_ref' => 'urn:li:video:abc', 'state' => 'processing']],
    ]);

    Http::fake([
        'api.linkedin.com/rest/videos/*' => Http::response(['message' => 'Service Unavailable'], 503),
    ]);

    $ctx = new PublishContext($target, ['hi'], [$media], $account, ['access_token' => 'tok']);
    $result = app(LinkedInConnector::class)->publish($ctx);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::MediaProcessing);
});

test('PROCESSING_FAILED video returns ServerError', function (): void {
    $account = ConnectedAccount::factory()->state(['platform' => 'linkedin'])->create();
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'media_upload_state' => [$media->id => ['remote_ref' => 'urn:li:video:abc', 'state' => 'processing']],
    ]);

    Http::fake([
        'api.linkedin.com/rest/videos/*' => Http::response(['status' => 'PROCESSING_FAILED'], 200),
    ]);

    $ctx = new PublishContext($target, ['hi'], [$media], $account, ['access_token' => 'tok']);
    $result = app(LinkedInConnector::class)->publish($ctx);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::ServerError);
});
