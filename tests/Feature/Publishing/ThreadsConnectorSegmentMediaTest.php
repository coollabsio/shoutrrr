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
