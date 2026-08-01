<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\ThreadsConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('media attaches to the section the resolver assigned, not always the first', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/pic.jpg',
        'mime' => 'image/jpeg',
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::Threads->value]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Threads->value,
        'remote_account_id' => 'threads123',
    ]);

    $context = new PublishContext(
        target: $target,
        segments: ['first (no media)', 'second (has media)'],
        media: [$media],
        account: $account,
        credentials: ['access_token' => 'threads-tok'],
        mediaBySection: [1 => [$media]],
    );

    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::sequence()
            ->push(['id' => 'container-1'])
            ->push(['id' => 'container-2']),
        'https://graph.threads.net/v1.0/container-1*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/container-2*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/threads123/threads_publish' => Http::sequence()
            ->push(['id' => 'post-1'])
            ->push(['id' => 'post-2']),
    ]);

    $result = app(ThreadsConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['post-1', 'post-2']);

    // The first segment's container is a plain TEXT container (no media).
    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['text'] === 'first (no media)'
        && $request['media_type'] === 'TEXT');

    // The second segment's container carries the image and chains to the first post.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['text'] === 'second (has media)'
        && $request['media_type'] === 'IMAGE'
        && str_contains((string) $request['image_url'], 'pic.jpg')
        && $request['reply_to_id'] === 'post-1');
});

test('a media-only first segment keeps its own container and does not leak media to the following text segment', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/pic.jpg',
        'mime' => 'image/jpeg',
    ]);

    $target = PostTarget::factory()->create(['platform' => Platform::Threads->value]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Threads->value,
        'remote_account_id' => 'threads123',
    ]);

    // Segment 0 is media-only (empty text); segment 1 is text-only with no media
    // assigned to it. The original (pre-filter) indices are what mediaBySection
    // and the reply chain must stay aligned to.
    $context = new PublishContext(
        target: $target,
        segments: ['', 'second (text only)'],
        media: [$media],
        account: $account,
        credentials: ['access_token' => 'threads-tok'],
        mediaBySection: [0 => [$media]],
    );

    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::sequence()
            ->push(['id' => 'container-1'])
            ->push(['id' => 'container-2']),
        'https://graph.threads.net/v1.0/container-1*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/container-2*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/threads123/threads_publish' => Http::sequence()
            ->push(['id' => 'post-1'])
            ->push(['id' => 'post-2']),
    ]);

    $result = app(ThreadsConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['post-1', 'post-2']);

    // Two containers must be created: one per segment.
    Http::assertSentCount(6);

    // Segment 0's container is a media-only IMAGE container with empty text and
    // no reply_to_id (it's the first post in the thread).
    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['media_type'] === 'IMAGE'
        && str_contains((string) $request['image_url'], 'pic.jpg')
        && $request['text'] === ''
        && ! isset($request['reply_to_id']));

    // Segment 1's container is a plain TEXT container carrying its own text, no
    // leaked media, and chains to segment 0's published post.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['media_type'] === 'TEXT'
        && $request['text'] === 'second (text only)'
        && $request['reply_to_id'] === 'post-1');
});
