<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
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

test('google business profile connector maps Event and Offer data without media', function () {
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
            'coupon_code' => 'SAVE20',
            'redemption_url' => 'https://example.test/redeem',
            'terms' => 'New customers only',
        ]],
    ]);

    app(GoogleBusinessProfileConnector::class)->publish(new PublishContext(
        target: $target,
        segments: ['Offer summary'],
        media: [],
        account: $account,
        credentials: ['access_token' => 'token'],
    ));

    Http::assertSent(fn (Request $request): bool => $request['topicType'] === 'OFFER'
        && $request['offer']['couponCode'] === 'SAVE20'
        && $request['offer']['redeemOnlineUrl'] === 'https://example.test/redeem'
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

test('google business profile connector maps auth and server failures', function (int $status, string $kind) {
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
        ->and($result->httpStatus)->toBe($status);
})->with([
    'expired token' => [401, 'auth_expired'],
    'permission denied' => [403, 'validation'],
    'server failure' => [500, 'server_error'],
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
