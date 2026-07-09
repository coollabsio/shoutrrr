<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\ThreadsConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<string>  $segments
 * @param  list<PostMedia>  $media
 * @param  array<string, mixed>  $targetOverrides
 */
function threadsContext(array $segments, array $media = [], array $targetOverrides = []): PublishContext
{
    $target = PostTarget::factory()->create(array_merge(['platform' => Platform::Threads->value], $targetOverrides));
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Threads->value,
        'remote_account_id' => 'threads123',
    ]);

    return new PublishContext(
        target: $target,
        segments: $segments,
        media: $media,
        account: $account,
        credentials: ['access_token' => 'threads-tok'],
    );
}

test('threads publishes a single text post through the container flow', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::response(['id' => 'container-1']),
        'https://graph.threads.net/v1.0/container-1*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/threads123/threads_publish' => Http::response(['id' => 'post-1']),
    ]);

    $result = app(ThreadsConnector::class)->publish(threadsContext(['hello world']));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['post-1']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['media_type'] === 'TEXT'
        && $request['text'] === 'hello world'
        && ! isset($request['reply_to_id']));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads_publish')
        && $request['creation_id'] === 'container-1');
});

test('threads publishes a 2-segment thread chaining reply_to_id and accumulates remote_ids', function () {
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

    $context = threadsContext(['first', 'second']);
    $result = app(ThreadsConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['post-1', 'post-2']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['text'] === 'second'
        && $request['reply_to_id'] === 'post-1');

    expect($context->target->fresh()->remote_id)->toBe('post-1')
        ->and($context->target->fresh()->remote_ids)->toBe(['post-1', 'post-2']);
});

test('threads resumes a partial chain from persisted remote_ids, continuing at the next segment', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::response(['id' => 'container-2']),
        'https://graph.threads.net/v1.0/container-2*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/threads123/threads_publish' => Http::response(['id' => 'post-2']),
    ]);

    $context = threadsContext(['first', 'second'], [], [
        'remote_id' => 'post-1',
        'remote_ids' => ['post-1'],
    ]);

    $result = app(ThreadsConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['post-1', 'post-2']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['text'] === 'second'
        && $request['reply_to_id'] === 'post-1');

    // Segment 1 must not be re-created; only segment 2's container + publish + poll fire.
    Http::assertSentCount(3);
});

test('threads publishes a single image as an IMAGE container', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');

    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::response(['id' => 'container-1']),
        'https://graph.threads.net/v1.0/container-1*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/threads123/threads_publish' => Http::response(['id' => 'post-1']),
    ]);

    $result = app(ThreadsConnector::class)->publish(threadsContext(['look at this'], [$media]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['post-1']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['media_type'] === 'IMAGE'
        && str_contains((string) $request['image_url'], 'pic.jpg')
        && $request['text'] === 'look at this');
});

test('threads publishes a caption-less image as an IMAGE container with empty text', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');

    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::response(['id' => 'container-1']),
        'https://graph.threads.net/v1.0/container-1*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/threads123/threads_publish' => Http::response(['id' => 'post-1']),
    ]);

    // Blank segment + media: valid on Threads, must not be rejected as empty.
    $result = app(ThreadsConnector::class)->publish(threadsContext([''], [$media]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['post-1']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['media_type'] === 'IMAGE'
        && $request['text'] === '');
});

test('threads rejects a post with neither text nor media', function () {
    $result = app(ThreadsConnector::class)->publish(threadsContext(['']));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation);
});

test('threads returns a MediaProcessing failure while the container status is IN_PROGRESS, polling with fields=status', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::response(['id' => 'container-1']),
        'https://graph.threads.net/v1.0/container-1*' => Http::response(['status' => 'IN_PROGRESS']),
    ]);

    $result = app(ThreadsConnector::class)->publish(threadsContext(['processing']));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::MediaProcessing)
        ->and($result->retryAfter)->toBe(6);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/container-1')
        && ($request['fields'] ?? null) === 'status');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'threads_publish'));
});

test('threads builds a carousel from two images then publishes the parent container', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/a.jpg', 'a-bytes');
    Storage::disk('public')->put('media/b.jpg', 'b-bytes');

    $first = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/a.jpg', 'mime' => 'image/jpeg']);
    $second = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/b.jpg', 'mime' => 'image/jpeg']);

    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::sequence()
            ->push(['id' => 'child-1'])
            ->push(['id' => 'child-2'])
            ->push(['id' => 'parent-1']),
        'https://graph.threads.net/v1.0/parent-1*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/threads123/threads_publish' => Http::response(['id' => 'post-carousel']),
    ]);

    $result = app(ThreadsConnector::class)->publish(threadsContext(['carousel caption'], [$first, $second]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['post-carousel']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && ($request['is_carousel_item'] ?? null) === 'true'
        && str_contains((string) ($request['image_url'] ?? ''), 'a.jpg'));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && ($request['media_type'] ?? null) === 'CAROUSEL'
        && ($request['children'] ?? null) === 'child-1,child-2'
        && ($request['text'] ?? null) === 'carousel caption');
});

test('threads publishes a video as a VIDEO container', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/clip.mp4', 'mp4-bytes');

    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/clip.mp4']);

    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::response(['id' => 'video-container']),
        'https://graph.threads.net/v1.0/video-container*' => Http::response(['status' => 'FINISHED']),
        'https://graph.threads.net/v1.0/threads123/threads_publish' => Http::response(['id' => 'video-post']),
    ]);

    $result = app(ThreadsConnector::class)->publish(threadsContext(['watch this'], [$media]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['video-post']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/threads123/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && $request['media_type'] === 'VIDEO'
        && str_contains((string) $request['video_url'], 'clip.mp4'));
});

test('threads maps a 401 to AuthExpired', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/threads123/threads' => Http::response(['error' => ['message' => 'expired']], 401),
    ]);

    $result = app(ThreadsConnector::class)->publish(threadsContext(['hi']));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::AuthExpired);
});

test('threads fails fast with no access token and makes no http calls', function () {
    Http::fake();

    $target = PostTarget::factory()->create(['platform' => Platform::Threads->value]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Threads->value, 'remote_account_id' => 'threads123']);

    $context = new PublishContext(
        target: $target,
        segments: ['hi'],
        media: [],
        account: $account,
        credentials: [],
    );

    $result = app(ThreadsConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::AuthExpired);

    Http::assertNothingSent();
});

test('threads delete removes every post in the chain, best-effort', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/post-1*' => Http::response([], 404),
        'https://graph.threads.net/v1.0/post-2*' => Http::response(['success' => true]),
    ]);

    $target = PostTarget::factory()->create([
        'platform' => Platform::Threads->value,
        'remote_id' => 'post-1',
        'remote_ids' => ['post-1', 'post-2'],
    ]);

    app(ThreadsConnector::class)->delete($target, ['access_token' => 'tok']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1.0/post-1') && $request->method() === 'DELETE');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1.0/post-2') && $request->method() === 'DELETE');
});
