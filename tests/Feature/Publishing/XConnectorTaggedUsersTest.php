<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\XConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * @param  list<array{handle: string, id: string|null}>  $tagged
 * @param  list<string>  $segments
 * @param  array<int, list<PostMedia>>  $mediaBySection
 */
function taggedContext(array $tagged, array $segments, array $mediaBySection): PublishContext
{
    return new PublishContext(
        target: PostTarget::factory()->create(['platform' => Platform::X->value, 'tagged_users' => $tagged]),
        segments: $segments,
        media: array_merge(...array_values($mediaBySection)),
        account: ConnectedAccount::factory()->create(['platform' => Platform::X->value]),
        credentials: ['access_token' => 'tok'],
        mediaBySection: $mediaBySection,
    );
}

function taggedMedia(string $mime = 'image/jpeg'): PostMedia
{
    Storage::fake('public');
    Storage::disk('public')->put('media/file', 'bytes');

    return PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/file', 'mime' => $mime]);
}

test('x tags resolved people on a photo tweet and stores their ids for the next publish', function () {
    Http::fake([
        'https://api.x.com/2/users/by*' => Http::response(['data' => [
            ['id' => '11', 'username' => 'Ada'],
            ['id' => '22', 'username' => 'grace'],
        ]]),
        'https://api.x.com/2/media/upload' => Http::response(['data' => ['id' => '99001']]),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    $context = taggedContext(
        [['handle' => 'ada', 'id' => null], ['handle' => 'Grace', 'id' => null]],
        ['look'],
        [0 => [taggedMedia()]],
    );

    expect(app(XConnector::class)->publish($context)->isSuccessful())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
        && $request['media'] === ['media_ids' => ['99001'], 'tagged_user_ids' => ['11', '22']]);
    Http::assertSentCount(3);
    expect($context->target->fresh()->tagged_users)->toBe([
        ['handle' => 'ada', 'id' => '11'],
        ['handle' => 'Grace', 'id' => '22'],
    ]);
});

test('x does not look up handles that already have a stored id', function () {
    Http::fake([
        'https://api.x.com/2/media/upload' => Http::response(['data' => ['id' => '99001']]),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    $context = taggedContext([['handle' => 'ada', 'id' => '11']], ['look'], [0 => [taggedMedia()]]);

    app(XConnector::class)->publish($context);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/by'));
    Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
        && $request['media']['tagged_user_ids'] === ['11']);
});

test('x sends at most ten tagged user ids', function () {
    Http::fake([
        'https://api.x.com/2/media/upload' => Http::response(['data' => ['id' => '99001']]),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    $tagged = array_map(fn (int $n): array => ['handle' => "user{$n}", 'id' => (string) $n], range(1, 12));

    app(XConnector::class)->publish(taggedContext($tagged, ['look'], [0 => [taggedMedia()]]));

    Http::assertSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
        && $request['media']['tagged_user_ids'] === array_map(strval(...), range(1, 10)));
});

test('x never sends tagged user ids with a gif', function () {
    Http::fake([
        'https://api.x.com/2/media/upload*' => Http::response(['data' => ['id' => 'gif1', 'processing_info' => ['state' => 'succeeded']]]),
        'https://api.twitter.com/2/tweets' => Http::response(['data' => ['id' => '111']]),
    ]);

    $result = app(XConnector::class)->publish(
        taggedContext([['handle' => 'ada', 'id' => '11']], ['moving'], [0 => [taggedMedia('image/gif')]]),
    );

    expect($result->isSuccessful())->toBeTrue();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/by'));
    Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets'
        && isset($request['media']['tagged_user_ids']));
});

test('x tags only the first thread segment that carries photos', function () {
    Http::fake([
        'https://api.x.com/2/media/upload' => Http::response(['data' => ['id' => '99001']]),
        'https://api.twitter.com/2/tweets' => Http::sequence()
            ->push(['data' => ['id' => '111']])
            ->push(['data' => ['id' => '222']])
            ->push(['data' => ['id' => '333']]),
    ]);

    $photo = taggedMedia();

    app(XConnector::class)->publish(taggedContext(
        [['handle' => 'ada', 'id' => '11']],
        ['intro', 'first photo', 'second photo'],
        [1 => [$photo], 2 => [$photo]],
    ));

    $tweets = Http::recorded(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets')->values();

    expect($tweets[0][0]->data())->not->toHaveKey('media')
        ->and($tweets[1][0]['media']['tagged_user_ids'])->toBe(['11'])
        ->and($tweets[2][0]['media'])->not->toHaveKey('tagged_user_ids');
});

test('x fails without posting when a tagged handle does not exist', function () {
    Http::fake([
        'https://api.x.com/2/users/by*' => Http::response([
            'data' => [['id' => '11', 'username' => 'ada']],
            'errors' => [['detail' => 'Could not find user with usernames: [nobody].']],
        ]),
    ]);

    $result = app(XConnector::class)->publish(taggedContext(
        [['handle' => 'ada', 'id' => null], ['handle' => 'nobody', 'id' => null]],
        ['look'],
        [0 => [taggedMedia()]],
    ));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation)
        ->and($result->errorMessage)->toContain('@nobody');
    Http::assertNotSent(fn ($request) => $request->url() === 'https://api.twitter.com/2/tweets');
});
