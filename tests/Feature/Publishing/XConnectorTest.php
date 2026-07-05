<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Media\CompressionResult;
use App\Services\Media\ImageCompressor;
use App\Services\Publishing\Connectors\XConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function xContext(array $segments, array $media = []): PublishContext
{
    $target = PostTarget::factory()->create(['platform' => Platform::X->value]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X->value]);

    return new PublishContext(
        target: $target,
        segments: $segments,
        media: $media,
        account: $account,
        credentials: ['access_token' => 'tok'],
    );
}

test('x posts a single tweet', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    $result = app(XConnector::class)->publish(xContext(['hello world']));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['111']);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
        && $request['text'] === 'hello world');
});

test('x threads replies to the previous tweet id', function () {
    $ids = ['111', '222'];
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::sequence()
            ->push(['data' => ['id' => '111']])
            ->push(['data' => ['id' => '222']]),
    ]);

    $result = app(XConnector::class)->publish(xContext(['first', 'second']));

    expect($result->remoteIds)->toBe($ids);

    Http::assertSent(fn ($request) => isset($request['reply'])
        && $request['reply']['in_reply_to_tweet_id'] === '111');
});

test('x maps 429 to RateLimited', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['title' => 'Too Many Requests'], 429),
    ]);

    $result = app(XConnector::class)->publish(xContext(['hi']));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::RateLimited)
        ->and($result->httpStatus)->toBe(429);
});

test('x maps 400 to Validation', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['title' => 'Invalid'], 400),
    ]);

    expect(app(XConnector::class)->publish(xContext(['hi']))->errorKind)
        ->toBe(ErrorKind::Validation);
});

test('x maps 5xx to ServerError', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response([], 503),
    ]);

    expect(app(XConnector::class)->publish(xContext(['hi']))->errorKind)
        ->toBe(ErrorKind::ServerError);
});

test('x maps 403 duplicate body to DuplicateContent (terminal)', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response([
            'detail' => 'You are not allowed to create a Tweet with duplicate content.',
        ], 403),
    ]);

    $kind = app(XConnector::class)->publish(xContext(['hi']))->errorKind;

    expect($kind)->toBe(ErrorKind::DuplicateContent)
        ->and($kind->isRetryable())->toBeFalse();
});

test('x maps 403 with error code 187 to DuplicateContent', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response([
            'errors' => [['code' => 187, 'message' => 'Status is a duplicate.']],
        ], 403),
    ]);

    expect(app(XConnector::class)->publish(xContext(['hi']))->errorKind)
        ->toBe(ErrorKind::DuplicateContent);
});

test('x maps 403 non-duplicate to Validation (terminal)', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response([
            'title' => 'Forbidden',
            'detail' => 'Your account is not permitted to perform this action.',
        ], 403),
    ]);

    $kind = app(XConnector::class)->publish(xContext(['hi']))->errorKind;

    expect($kind)->toBe(ErrorKind::Validation)
        ->and($kind->isRetryable())->toBeFalse();
});

test('x maps 401 to AuthExpired', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['title' => 'Unauthorized'], 401),
    ]);

    expect(app(XConnector::class)->publish(xContext(['hi']))->errorKind)
        ->toBe(ErrorKind::AuthExpired);
});

test('x carries retry-after on a 429', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['title' => 'Too Many Requests'], 429, ['Retry-After' => '42']),
    ]);

    $result = app(XConnector::class)->publish(xContext(['hi']));

    expect($result->errorKind)->toBe(ErrorKind::RateLimited)
        ->and($result->retryAfter)->toBe(42);
});

test('x persists each segment id before sending the next (mid-thread death is safe)', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::sequence()
            ->push(['data' => ['id' => '111']])
            ->push([], 500),
    ]);

    $context = xContext(['first', 'second']);

    $result = app(XConnector::class)->publish($context);

    expect($result->isSuccessful())->toBeFalse();

    // Segment 0 must be persisted to the DB before segment 1 was attempted.
    expect($context->target->fresh()->remote_ids)->toBe(['111']);
    expect($context->target->fresh()->remote_id)->toBe('111');
});

test('x returns a failure when media upload fails instead of an empty media id', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
    ]);

    Http::fake([
        'https://api.x.com/2/media/upload' => Http::response(['title' => 'Bad media'], 400),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    $result = app(XConnector::class)->publish(xContext(['hi'], [$media]));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation);

    Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
});

test('x uploads media to the v2 endpoint and attaches data.id to the first tweet', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
    ]);

    Http::fake([
        'https://api.x.com/2/media/upload' => Http::response(['data' => ['id' => '99001', 'media_key' => '3_99001']]),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    $result = app(XConnector::class)->publish(xContext(['look'], [$media]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['111']);

    // Bytes uploaded to the v2 endpoint, with a media_category multipart field.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.x.com/2/media/upload'
        && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'media_category'));

    // Attached the returned data.id (not the old media_id_string) to the first tweet.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
        && ($request['media']['media_ids'] ?? null) === ['99001']);
});

test('x uploads gifs through the async tweet_gif flow', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/animation.gif', 'gif-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/animation.gif',
        'mime' => 'image/gif',
        'size_bytes' => strlen('gif-bytes'),
    ]);

    Http::fake([
        'https://api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => 'gif123']], 200),
        'https://api.x.com/2/media/upload/gif123/append' => Http::response([], 200),
        'https://api.x.com/2/media/upload/gif123/finalize' => Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]], 200),
        'https://api.x.com/2/media/upload*' => Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]], 200),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 'tweet1']], 201),
    ]);

    $result = app(XConnector::class)->publish(xContext(['gif'], [$media]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['tweet1']);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.x.com/2/media/upload/initialize'
        && $request['media_category'] === 'tweet_gif'
        && $request['media_type'] === 'image/gif'
        && $request['total_bytes'] === strlen('gif-bytes'));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
        && ($request['media']['media_ids'] ?? null) === ['gif123']);
});

test('x rejects mixing a gif with other media before calling the api', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/animation.gif', 'gif-bytes');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $gif = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/animation.gif',
        'mime' => 'image/gif',
    ]);
    $image = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
    ]);

    Http::fake();

    $result = app(XConnector::class)->publish(xContext(['mixed'], [$gif, $image]));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain('one GIF');

    Http::assertNothingSent();
});

test('x omits an empty text field for a media-only post', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
    ]);

    Http::fake([
        'https://api.x.com/2/media/upload' => Http::response(['data' => ['id' => '99001']]),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    // A photo-only post splits to a single empty segment.
    $result = app(XConnector::class)->publish(xContext([''], [$media]));

    expect($result->isSuccessful())->toBeTrue();

    // The tweet carries the media but NO `text` key — X rejects `text: ""` with
    // a 400 "Invalid Request" even when media is attached.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
        && ! array_key_exists('text', $request->data())
        && ($request['media']['media_ids'] ?? null) === ['99001']);
});

test('x quotes a pasted status link and strips it from the text', function () {
    config(['instance.defaults.quote_tweets_enabled' => true]);
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    $result = app(XConnector::class)->publish(xContext([
        'great thread 👇 https://x.com/foo/status/1234567890',
    ]));

    expect($result->isSuccessful())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
        && $request['text'] === 'great thread 👇'
        && ($request['quote_tweet_id'] ?? null) === '1234567890');
});

test('x quotes twitter.com links and tolerates query strings', function () {
    config(['instance.defaults.quote_tweets_enabled' => true]);
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    app(XConnector::class)->publish(xContext([
        'look https://twitter.com/foo/status/999?s=20',
    ]));

    Http::assertSent(fn ($request) => $request['text'] === 'look'
        && ($request['quote_tweet_id'] ?? null) === '999');
});

test('x quotes the last status link when several are present', function () {
    config(['instance.defaults.quote_tweets_enabled' => true]);
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    app(XConnector::class)->publish(xContext([
        'https://x.com/a/status/111 vs https://x.com/b/status/222',
    ]));

    Http::assertSent(fn ($request) => ($request['quote_tweet_id'] ?? null) === '222'
        && $request['text'] === 'https://x.com/a/status/111 vs');
});

test('x omits text entirely for a quote-only tweet', function () {
    config(['instance.defaults.quote_tweets_enabled' => true]);
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    app(XConnector::class)->publish(xContext(['https://x.com/foo/status/1234567890']));

    Http::assertSent(fn ($request) => ! array_key_exists('text', $request->data())
        && ($request['quote_tweet_id'] ?? null) === '1234567890');
});

test('x does not treat a non-status x.com link as a quote', function () {
    config(['instance.defaults.quote_tweets_enabled' => true]);
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    app(XConnector::class)->publish(xContext(['follow me https://x.com/foo']));

    Http::assertSent(fn ($request) => $request['text'] === 'follow me https://x.com/foo'
        && ! array_key_exists('quote_tweet_id', $request->data()));
});

test('x leaves a status link inline when quote tweets are disabled (the default)', function () {
    Http::fake([
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    app(XConnector::class)->publish(xContext([
        'great thread 👇 https://x.com/foo/status/1234567890',
    ]));

    // Off by default: the link is posted verbatim, no quote_tweet_id.
    Http::assertSent(fn ($request) => $request['text'] === 'great thread 👇 https://x.com/foo/status/1234567890'
        && ! array_key_exists('quote_tweet_id', $request->data()));
});

test('x leaves a status link inline and skips quoting when media is attached', function () {
    config(['instance.defaults.quote_tweets_enabled' => true]);
    Storage::fake('public');
    Storage::disk('public')->put('media/cat.jpg', 'image-bytes');

    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/cat.jpg',
        'mime' => 'image/jpeg',
    ]);

    Http::fake([
        'https://api.x.com/2/media/upload' => Http::response(['data' => ['id' => '99001']]),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    app(XConnector::class)->publish(xContext([
        'see https://x.com/foo/status/1234567890',
    ], [$media]));

    // quote_tweet_id is mutually exclusive with media, so the link stays in the copy.
    Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
        && $request['text'] === 'see https://x.com/foo/status/1234567890'
        && ! array_key_exists('quote_tweet_id', $request->data())
        && ($request['media']['media_ids'] ?? null) === ['99001']);
});

test('x compresses oversized images via the compressor before upload', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/big.jpg', 'image-bytes');

    $compressor = Mockery::mock(ImageCompressor::class);
    $compressor->shouldReceive('compressToFit')
        ->once()
        ->with('image-bytes', Platform::X->maxMediaBytes(), 'image/jpeg', Platform::X->allowedMime())
        ->andReturn(CompressionResult::compressed('small-bytes', 'image/webp'));
    app()->instance(ImageCompressor::class, $compressor);

    $media = PostMedia::factory()->create([
        'disk' => 'public', 'path' => 'media/big.jpg', 'mime' => 'image/jpeg',
    ]);

    Http::fake([
        'https://api.x.com/2/media/upload' => Http::response(['data' => ['id' => '123']]),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 'tweet1']]),
    ]);

    app(XConnector::class)->publish(xContext(['look'], [$media]));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'media/upload')
        && str_contains((string) $request->body(), 'small-bytes'));
});
