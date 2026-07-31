<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('media attaches to the section the resolver assigned, not always the first', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
        'alt_text' => 'a cat',
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::Bluesky->value]);
    $account = ConnectedAccount::factory()->bluesky()->create(['remote_account_id' => 'did:plc:me']);

    $context = new PublishContext(
        target: $target,
        segments: ['first (no media)', 'second (has media)'],
        media: [$media],
        account: $account,
        credentials: ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']],
        mediaBySection: [1 => [$media]],
    );

    Http::fake([
        '*com.atproto.repo.uploadBlob' => Http::response([
            'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafblob'], 'mimeType' => 'image/jpeg', 'size' => 11],
        ]),
        '*com.atproto.repo.createRecord' => Http::sequence()
            ->push(['uri' => 'at://r/1', 'cid' => 'cid1'])
            ->push(['uri' => 'at://r/2', 'cid' => 'cid2']),
    ]);

    $result = app(BlueskyPublishConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['at://r/1', 'at://r/2']);

    $requests = Http::recorded(fn ($request) => str_contains($request->url(), 'com.atproto.repo.createRecord'))
        ->values();

    expect($requests)->toHaveCount(2);

    $firstRecord = $requests[0][0]['record'];
    $secondRecord = $requests[1][0]['record'];

    expect($firstRecord['text'])->toBe('first (no media)')
        ->and($firstRecord)->not->toHaveKey('embed');

    expect($secondRecord['text'])->toBe('second (has media)')
        ->and($secondRecord['embed']['$type'] ?? null)->toBe('app.bsky.embed.images')
        ->and($secondRecord['embed']['images'][0]['alt'] ?? null)->toBe('a cat')
        ->and($secondRecord['embed']['images'][0]['image']['ref']['$link'] ?? null)->toBe('bafblob');
});
