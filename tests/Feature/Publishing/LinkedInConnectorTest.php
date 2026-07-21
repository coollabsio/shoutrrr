<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Media\CompressionResult;
use App\Services\Media\ImageCompressor;
use App\Services\Publishing\Connectors\LinkedInConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<PostMedia>  $media
 */
function liContext(array $segments, array $media = []): PublishContext
{
    $target = PostTarget::factory()->create(['platform' => Platform::LinkedIn->value]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::LinkedIn->value,
        'remote_account_id' => 'person123',
    ]);

    return new PublishContext(
        target: $target,
        segments: $segments,
        media: $media,
        account: $account,
        credentials: ['access_token' => 'tok'],
    );
}

test('linkedin creates a single post and returns the urn', function () {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:99']),
    ]);

    $result = app(LinkedInConnector::class)->publish(liContext(['hello']));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['urn:li:share:99']);

    Http::assertSent(fn ($request) => $request['commentary'] === 'hello'
        && $request['author'] === 'urn:li:person:person123');
});

test('linkedin sends article content for a text-only link post', function () {
    Http::fake([
        'https://lnkd.in/dth5NUVP' => Http::response(
            '<html><head><meta property="og:title" content="coolLabs"><meta property="og:description" content="Software without compromise."><meta property="og:image" content="https://coollabs.io/og.png"></head></html>',
            200,
        ),
        'https://coollabs.io/og.png' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response([
            'value' => ['uploadUrl' => 'https://upload.linkedin.com/put/preview', 'image' => 'urn:li:image:preview'],
        ]),
        'https://upload.linkedin.com/put/preview' => Http::response('', 201),
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:99']),
    ]);

    $result = app(LinkedInConnector::class)->publish(liContext(['testing things https://lnkd.in/dth5NUVP']));

    expect($result->isSuccessful())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.linkedin.com/rest/posts'
        && ($request['content']['article']['source'] ?? null) === 'https://lnkd.in/dth5NUVP'
        && ($request['content']['article']['title'] ?? null) === 'coolLabs'
        && ($request['content']['article']['description'] ?? null) === 'Software without compromise.'
        && ($request['content']['article']['thumbnail'] ?? null) === 'urn:li:image:preview');
});

test('linkedin uses a default article thumbnail content type when the image response omits one', function () {
    Http::fake([
        'https://example.com/post' => Http::response(
            '<html><head><meta property="og:title" content="Example"><meta property="og:image" content="https://cdn.example.com/preview"></head></html>',
            200,
        ),
        'https://cdn.example.com/preview' => Http::response('image-bytes', 200),
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response([
            'value' => ['uploadUrl' => 'https://upload.linkedin.com/put/preview', 'image' => 'urn:li:image:preview'],
        ]),
        'https://upload.linkedin.com/put/preview' => Http::response('', 201),
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:99']),
    ]);

    $result = app(LinkedInConnector::class)->publish(liContext(['testing https://example.com/post']));

    expect($result->isSuccessful())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://upload.linkedin.com/put/preview'
        && $request->body() === 'image-bytes'
        && $request->hasHeader('Content-Type', 'image/jpeg'));
});

test('linkedin falls back to text-only when link metadata cannot be fetched', function () {
    Http::fake([
        'https://example.com/*' => Http::response('', 404),
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:99']),
    ]);

    $result = app(LinkedInConnector::class)->publish(liContext(['hello https://example.com/missing']));

    expect($result->isSuccessful())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.linkedin.com/rest/posts'
        && ! isset($request['content']));
});

test('linkedin registers + uploads media and references the asset urn', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.png', 'png-bytes');

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

    $result = app(LinkedInConnector::class)->publish(liContext(['look'], [$media]));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['urn:li:share:7']);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.linkedin.com/rest/images?action=initializeUpload'
        && ($request['initializeUploadRequest']['owner'] ?? null) === 'urn:li:person:person123');

    Http::assertSent(fn ($request) => $request->url() === 'https://upload.linkedin.com/put/abc'
        && $request->body() === 'png-bytes');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.linkedin.com/rest/posts'
        && ($request['content']['media']['id'] ?? null) === 'urn:li:image:42'
        && ($request['content']['media']['altText'] ?? null) === 'a picture');
});

test('linkedin includes alt text per image for a multi-image post', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/a.png', 'a-bytes');
    Storage::disk('public')->put('media/b.png', 'b-bytes');

    $first = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/a.png', 'mime' => 'image/png', 'alt_text' => 'first']);
    $second = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/b.png', 'mime' => 'image/png', 'alt_text' => 'second']);

    Http::fake([
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::sequence()
            ->push(['value' => ['uploadUrl' => 'https://upload.linkedin.com/put/a', 'image' => 'urn:li:image:1']])
            ->push(['value' => ['uploadUrl' => 'https://upload.linkedin.com/put/b', 'image' => 'urn:li:image:2']]),
        'https://upload.linkedin.com/put/*' => Http::response('', 201),
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:9']),
    ]);

    app(LinkedInConnector::class)->publish(liContext(['look'], [$first, $second]));

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://api.linkedin.com/rest/posts') {
            return false;
        }

        $images = $request['content']['multiImage']['images'] ?? null;

        return is_array($images)
            && ($images[0]['altText'] ?? null) === 'first'
            && ($images[1]['altText'] ?? null) === 'second';
    });
});

test('linkedin fails when no post id is returned', function () {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201),
    ]);

    $result = app(LinkedInConnector::class)->publish(liContext(['hi']));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::ServerError)
        ->and($result->errorMessage)->toBe('LinkedIn did not return a post id');
});

test('linkedin joins every segment into one post', function () {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:1']),
    ]);

    app(LinkedInConnector::class)->publish(liContext(['first', 'second']));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.linkedin.com/rest/posts'
        && $request['commentary'] === "first\nsecond");
});

test('linkedin escapes little-text reserved characters in commentary', function () {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:1']),
    ]);

    app(LinkedInConnector::class)->publish(liContext(['We shipped v2 (finally). Read more_here!']));

    Http::assertSent(fn ($request) => $request['commentary'] === 'We shipped v2 \(finally\). Read more\_here!');
});

test('linkedin preserves mention annotations while escaping surrounding text', function () {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:1']),
    ]);

    app(LinkedInConnector::class)->publish(liContext([
        'Built with @[coolLabs](urn:li:organization:2414183) (open source)',
    ]));

    Http::assertSent(fn ($request) => $request['commentary'] === 'Built with @[coolLabs](urn:li:organization:2414183) \(open source\)');
});

test('linkedin leaves hashtags unescaped so LinkedIn links them', function () {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:1']),
    ]);

    app(LinkedInConnector::class)->publish(liContext(['Shipping #v2 today (really) in C# too']));

    Http::assertSent(fn ($request) => $request['commentary'] === 'Shipping #v2 today \(really\) in C\# too');
});

test('linkedin throws rather than publishing empty commentary when escaping fails', function () {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:1']),
    ]);

    // Truncated multibyte sequence: the /u escape pattern bails with PREG_BAD_UTF8_ERROR.
    expect(fn () => app(LinkedInConnector::class)->publish(liContext(["broken \xC3 text"])))
        ->toThrow(RuntimeException::class, 'Failed to escape LinkedIn commentary');

    Http::assertNothingSent();
});

test('linkedin maps 401 to AuthExpired', function () {
    Http::fake(['https://api.linkedin.com/rest/posts' => Http::response(['message' => 'expired'], 401)]);

    expect(app(LinkedInConnector::class)->publish(liContext(['hi']))->errorKind)
        ->toBe(ErrorKind::AuthExpired);
});

test('linkedin compresses oversized images via the compressor before upload', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/big.jpg', 'image-bytes');

    $compressor = Mockery::mock(ImageCompressor::class);
    $compressor->shouldReceive('compressToFit')
        ->once()
        ->with('image-bytes', Platform::LinkedIn->maxMediaBytes(), 'image/jpeg', Platform::LinkedIn->allowedMime())
        ->andReturn(CompressionResult::compressed('small-bytes', 'image/jpeg'));
    app()->instance(ImageCompressor::class, $compressor);

    $media = PostMedia::factory()->create([
        'disk' => 'public', 'path' => 'media/big.jpg', 'mime' => 'image/jpeg',
    ]);

    Http::fake([
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response([
            'value' => ['uploadUrl' => 'https://upload.example/abc', 'image' => 'urn:li:image:1'],
        ]),
        'https://upload.example/abc' => Http::response('', 201),
        'https://api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:1']),
    ]);

    app(LinkedInConnector::class)->publish(liContext(['look'], [$media]));

    Http::assertSent(fn ($request) => $request->url() === 'https://upload.example/abc'
        && $request->body() === 'small-bytes'
        && $request->hasHeader('Content-Type', 'image/jpeg'));
});
