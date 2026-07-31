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
