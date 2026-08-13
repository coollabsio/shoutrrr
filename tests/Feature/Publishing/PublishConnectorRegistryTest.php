<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use App\Services\Publishing\Connectors\DiscordPublishConnector;
use App\Services\Publishing\Connectors\FacebookConnector;
use App\Services\Publishing\Connectors\GoogleBusinessProfileConnector;
use App\Services\Publishing\Connectors\InstagramConnector;
use App\Services\Publishing\Connectors\LinkedInConnector;
use App\Services\Publishing\Connectors\ThreadsConnector;
use App\Services\Publishing\Connectors\XConnector;
use App\Services\Publishing\PublishConnectorRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('registry resolves each platform to its connector', function () {
    $registry = app(PublishConnectorRegistry::class);

    expect($registry->for(Platform::X))->toBeInstanceOf(XConnector::class)
        ->and($registry->for(Platform::Bluesky))->toBeInstanceOf(BlueskyPublishConnector::class)
        ->and($registry->for(Platform::LinkedIn))->toBeInstanceOf(LinkedInConnector::class)
        ->and($registry->for(Platform::Facebook))->toBeInstanceOf(FacebookConnector::class)
        ->and($registry->for(Platform::Instagram))->toBeInstanceOf(InstagramConnector::class)
        ->and($registry->for(Platform::Threads))->toBeInstanceOf(ThreadsConnector::class)
        ->and($registry->for(Platform::Discord))->toBeInstanceOf(DiscordPublishConnector::class)
        ->and($registry->for(Platform::GoogleBusinessProfile))->toBeInstanceOf(GoogleBusinessProfileConnector::class);
});

test('google business profile connector creates a REST v4 local post', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts' => Http::response([
            'name' => 'accounts/one/locations/one/localPosts/post-one',
            'state' => 'PROCESSING',
            'searchUrl' => 'https://business.google.com/post-one',
        ], 200),
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::GoogleBusinessProfile,
        'capabilities' => ['google_business_profile' => ['locationResourceName' => 'accounts/one/locations/one']],
    ]);
    $context = new PublishContext(
        target: PostTarget::factory()->create(['platform' => Platform::GoogleBusinessProfile->value]),
        segments: ['A local post'],
        media: [],
        account: $account,
        credentials: ['access_token' => 'token'],
    );

    $result = app(PublishConnectorRegistry::class)
        ->for(Platform::GoogleBusinessProfile)
        ->publish($context);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['accounts/one/locations/one/localPosts/post-one'])
        ->and($result->remoteMetadata['state'])->toBe('PROCESSING');
    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('Authorization', 'Bearer token')
            && $request['summary'] === 'A local post'
            && $request['topicType'] === 'STANDARD';
    });
});

test('google business profile connector sends public media source URLs', function () {
    config([
        'filesystems.default' => 'public',
        'filesystems.disks.public.url' => 'https://media.example.test',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts' => Http::response([
            'name' => 'accounts/one/locations/one/localPosts/post-media',
        ]),
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::GoogleBusinessProfile,
        'capabilities' => ['google_business_profile' => ['locationResourceName' => 'accounts/one/locations/one']],
    ]);
    $media = PostMedia::factory()->create([
        'disk' => 'public',
        'path' => 'media/example.jpg',
        'mime' => 'image/jpeg',
    ]);

    $result = app(GoogleBusinessProfileConnector::class)->publish(new PublishContext(
        target: PostTarget::factory()->create(['platform' => Platform::GoogleBusinessProfile->value]),
        segments: ['A local post'], media: [$media], account: $account, credentials: ['access_token' => 'token'],
    ));

    expect($result->isSuccessful())->toBeTrue();
    Http::assertSent(function (Request $request) use ($media): bool {
        $sourceUrl = $request['media'][0]['sourceUrl'] ?? null;

        return is_string($sourceUrl)
            && str_contains($sourceUrl, '/published-media/'.$media->id)
            && str_contains($sourceUrl, 'signature=')
            && $request['media'][0]['mediaFormat'] === 'PHOTO';
    });
});

test('google business profile connector identifies video media to Google', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts' => Http::response([
            'name' => 'accounts/one/locations/one/localPosts/post-video',
        ]),
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::GoogleBusinessProfile,
        'capabilities' => ['google_business_profile' => ['locationResourceName' => 'accounts/one/locations/one']],
    ]);
    $video = PostMedia::factory()->video()->create();

    $result = app(GoogleBusinessProfileConnector::class)->publish(new PublishContext(
        target: PostTarget::factory()->create(['platform' => Platform::GoogleBusinessProfile->value]),
        segments: ['A local post'], media: [$video], account: $account, credentials: ['access_token' => 'token'],
    ));

    expect($result->isSuccessful())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request['media'][0]['mediaFormat'] === 'VIDEO');
});

test('google business profile connector publishes with a legacy canonical location key', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts' => Http::response([
            'name' => 'accounts/one/locations/one/localPosts/post-legacy',
        ]),
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::GoogleBusinessProfile,
        'capabilities' => ['google_business_profile' => ['key' => 'accounts/one/locations/one']],
    ]);

    $result = app(GoogleBusinessProfileConnector::class)->publish(new PublishContext(
        target: PostTarget::factory()->create(['platform' => Platform::GoogleBusinessProfile->value]),
        segments: ['A local post'], media: [], account: $account, credentials: ['access_token' => 'token'],
    ));

    expect($result->isSuccessful())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts');
});

test('google business profile connector rejects a malformed location capability before sending a request', function () {
    Http::preventStrayRequests();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::GoogleBusinessProfile,
        'capabilities' => ['google_business_profile' => ['locationResourceName' => 'locations/one']],
    ]);

    $result = app(GoogleBusinessProfileConnector::class)->publish(new PublishContext(
        target: PostTarget::factory()->create(['platform' => Platform::GoogleBusinessProfile->value]),
        segments: ['A local post'], media: [], account: $account, credentials: ['access_token' => 'token'],
    ));

    expect($result->errorKind->value)->toBe('validation')
        ->and($result->errorMessage)->toContain('location capability');
    Http::assertNothingSent();
});

test('google business profile connector maps an Event payload with a trimmed CTA URL', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts' => Http::response([
            'name' => 'accounts/one/locations/one/localPosts/post-event',
        ]),
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::GoogleBusinessProfile,
        'capabilities' => ['google_business_profile' => ['locationResourceName' => 'accounts/one/locations/one']],
    ]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::GoogleBusinessProfile->value,
        'provider_options' => ['google_business_profile' => [
            'local_post_type' => 'event',
            'title' => 'Open House',
            'start_at' => '2026-08-17T17:30:00Z',
            'end_at' => '2026-08-17T19:00:00Z',
            'cta_type' => 'learn_more',
            'cta_url' => ' https://example.test/event ',
        ]],
    ]);

    $result = app(GoogleBusinessProfileConnector::class)->publish(new PublishContext(
        target: $target, segments: ['A local event'], media: [], account: $account, credentials: ['access_token' => 'token'],
    ));

    expect($result->isSuccessful())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts'
        && $request['topicType'] === 'EVENT'
        && $request['callToAction'] === ['actionType' => 'LEARN_MORE', 'url' => 'https://example.test/event']
        && $request['event'] === [
            'title' => 'Open House',
            'schedule' => [
                'startDate' => ['year' => 2026, 'month' => 8, 'day' => 17],
                'startTime' => ['hours' => 17, 'minutes' => 30, 'seconds' => 0],
                'endDate' => ['year' => 2026, 'month' => 8, 'day' => 17],
                'endTime' => ['hours' => 19, 'minutes' => 0, 'seconds' => 0],
            ],
        ]
        && ! isset($request['event']['schedule']['startDateTime'])
        && ! isset($request['event']['schedule']['endDateTime'])
        && ! isset($request['offer']));
});

test('google business profile connector maps an Offer payload with its required Event schedule', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts' => Http::response([
            'name' => 'accounts/one/locations/one/localPosts/post-two',
        ]),
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::GoogleBusinessProfile,
        'capabilities' => ['google_business_profile' => ['locationResourceName' => 'accounts/one/locations/one']],
    ]);
    $target = PostTarget::factory()->create([
        'platform' => Platform::GoogleBusinessProfile->value,
        'provider_options' => ['google_business_profile' => [
            'local_post_type' => 'offer',
            'title' => 'Summer savings',
            'start_at' => '2026-08-17T17:30:00Z',
            'end_at' => '2026-08-17T19:00:00Z',
            'coupon_code' => 'SAVE20',
            'redemption_url' => 'https://example.test/redeem',
            'terms' => 'New customers only',
        ]],
    ]);

    $result = app(GoogleBusinessProfileConnector::class)->publish(new PublishContext(
        target: $target,
        segments: ['Offer summary'],
        media: [],
        account: $account,
        credentials: ['access_token' => 'token'],
    ));

    expect($result->isSuccessful())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts'
        && $request['topicType'] === 'OFFER'
        && $request['event'] === [
            'title' => 'Summer savings',
            'schedule' => [
                'startDate' => ['year' => 2026, 'month' => 8, 'day' => 17],
                'startTime' => ['hours' => 17, 'minutes' => 30, 'seconds' => 0],
                'endDate' => ['year' => 2026, 'month' => 8, 'day' => 17],
                'endTime' => ['hours' => 19, 'minutes' => 0, 'seconds' => 0],
            ],
        ]
        && $request['offer']['couponCode'] === 'SAVE20'
        && $request['offer']['redeemOnlineUrl'] === 'https://example.test/redeem'
        && ! isset($request['event']['schedule']['startDateTime'])
        && ! isset($request['event']['schedule']['endDateTime'])
        && ! isset($request['media']));
});

test('google business profile connector preserves quota retry timing', function () {
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts' => Http::response([
            'error' => ['message' => 'Quota exceeded'],
        ], 429, ['Retry-After' => '120']),
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::GoogleBusinessProfile,
        'capabilities' => ['google_business_profile' => ['locationResourceName' => 'accounts/one/locations/one']],
    ]);

    $result = app(GoogleBusinessProfileConnector::class)->publish(new PublishContext(
        target: PostTarget::factory()->create(['platform' => Platform::GoogleBusinessProfile->value]),
        segments: ['Summary'], media: [], account: $account, credentials: ['access_token' => 'token'],
    ));

    expect($result->errorKind->value)->toBe('rate_limited')
        ->and($result->retryAfter)->toBe(120);
});

test('google business profile connector maps auth and server failures', function (int $status, string $kind, bool $mayHaveCreatedRemote) {
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts' => Http::response(['error' => ['message' => 'failed']], $status),
    ]);
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::GoogleBusinessProfile,
        'capabilities' => ['google_business_profile' => ['locationResourceName' => 'accounts/one/locations/one']],
    ]);

    $result = app(GoogleBusinessProfileConnector::class)->publish(new PublishContext(
        target: PostTarget::factory()->create(['platform' => Platform::GoogleBusinessProfile->value]),
        segments: ['Summary'], media: [], account: $account, credentials: ['access_token' => 'token'],
    ));

    expect($result->errorKind->value)->toBe($kind)
        ->and($result->httpStatus)->toBe($status)
        ->and($result->mayHaveCreatedRemote)->toBe($mayHaveCreatedRemote);
})->with([
    'expired token' => [401, 'auth_expired', false],
    'permission denied' => [403, 'validation', false],
    'server failure' => [500, 'server_error', true],
]);

test('google business profile delete uses the canonical resource and accepts 404', function () {
    Http::fake([
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts/post-one' => Http::response([], 404),
    ]);
    $target = PostTarget::factory()->published()->create([
        'platform' => Platform::GoogleBusinessProfile->value,
        'remote_id' => 'legacy-id',
        'remote_metadata' => ['name' => 'accounts/one/locations/one/localPosts/post-one'],
    ]);

    app(GoogleBusinessProfileConnector::class)->delete($target, ['access_token' => 'token']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts/post-one');
});
