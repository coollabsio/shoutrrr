<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\XConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('media attaches to the section the resolver assigned, not always the first', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::X->value]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X->value]);

    $context = new PublishContext(
        target: $target,
        segments: ['first (no media)', 'second (has media)'],
        media: [$media],
        account: $account,
        credentials: ['access_token' => 'tok'],
        mediaBySection: [1 => [$media]],
    );

    Http::fake([
        'https://api.x.com/2/media/upload' => Http::response(['data' => ['id' => '99001']]),
        'https://api.twitter.com/2/tweets' => Http::sequence()
            ->push(['data' => ['id' => '111']])
            ->push(['data' => ['id' => '222']]),
    ]);

    $result = app(XConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['111', '222']);

    $requests = Http::recorded(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets')
        ->values();

    expect($requests)->toHaveCount(2);

    $firstRequest = $requests[0][0];
    $secondRequest = $requests[1][0];

    expect($firstRequest['text'])->toBe('first (no media)')
        ->and(array_key_exists('media', $firstRequest->data()))->toBeFalse();

    expect($secondRequest['text'])->toBe('second (has media)')
        ->and($secondRequest['media']['media_ids'] ?? null)->toBe(['99001']);
});

test('an image on one section and a video on another both publish, resolved per section', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $image = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
    ]);
    $video = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/v.mp4']);
    Storage::disk('public')->put('media/ws/v.mp4', str_repeat('x', 2048));

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X->value]);
    $target = PostTarget::factory()->for($account, 'account')->create([
        // Video already finished processing on a prior attempt, so this test only
        // needs to stub the status poll, not the full chunked upload flow.
        'media_upload_state' => [$video->id => ['remote_ref' => '77', 'state' => 'processing']],
    ]);

    $context = new PublishContext(
        target: $target,
        segments: ['first (image)', 'second (video)'],
        media: [$image, $video],
        account: $account,
        credentials: ['access_token' => 'tok'],
        mediaBySection: [0 => [$image], 1 => [$video]],
    );

    Http::fake([
        'https://api.x.com/2/media/upload' => Http::response(['data' => ['id' => '99001']]),
        'api.x.com/2/media/upload*' => Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]]),
        'https://api.twitter.com/2/tweets' => Http::sequence()
            ->push(['data' => ['id' => '111']])
            ->push(['data' => ['id' => '222']]),
    ]);

    $result = app(XConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['111', '222']);

    $requests = Http::recorded(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets')
        ->values();

    expect($requests)->toHaveCount(2);

    $firstRequest = $requests[0][0];
    $secondRequest = $requests[1][0];

    expect($firstRequest['text'])->toBe('first (image)')
        ->and($firstRequest['media']['media_ids'] ?? null)->toBe(['99001']);

    expect($secondRequest['text'])->toBe('second (video)')
        ->and($secondRequest['media']['media_ids'] ?? null)->toBe(['77']);

    // No chunked initialize call — the video's already-processed state was reused.
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/media/upload/initialize'));
});

test('a section with more images than the platform cap is trimmed locally, not rejected by the API', function (): void {
    Storage::fake('public');

    $media = PostMedia::factory()->count(5)->sequence(fn ($seq) => [
        'disk' => 'public',
        'path' => "media/img{$seq->index}.jpg",
        'mime' => 'image/jpeg',
    ])->create();

    foreach ($media as $index => $item) {
        Storage::disk('public')->put("media/img{$index}.jpg", 'image-bytes');
    }

    $target = PostTarget::factory()->create(['platform' => Platform::X->value]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X->value]);

    $context = new PublishContext(
        target: $target,
        segments: ['five images'],
        media: $media->all(),
        account: $account,
        credentials: ['access_token' => 'tok'],
        mediaBySection: [0 => $media->all()],
    );

    Http::fake([
        'https://api.x.com/2/media/upload' => Http::sequence()
            ->push(['data' => ['id' => '1']])
            ->push(['data' => ['id' => '2']])
            ->push(['data' => ['id' => '3']])
            ->push(['data' => ['id' => '4']]),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    $result = app(XConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue();

    $request = Http::recorded(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets')->values()[0][0];

    expect($request['media']['media_ids'] ?? null)->toHaveCount(4);
});
