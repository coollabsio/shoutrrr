<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\UsageEvent;
use App\Models\Workspace;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TIKTOK_CREATOR_INFO_URL = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';

beforeEach(function () {
    config()->set('services.tiktok.client_id', 'tiktok-client-key');
    config()->set('services.tiktok.client_secret', 'tiktok-client-secret');
});

/**
 * @param  array<string, mixed>  $attributes
 */
function creatorInfoAccount(Workspace $workspace, array $attributes = []): ConnectedAccount
{
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::TikTok->value,
        'token_expires_at' => now()->addHour(),
        ...$attributes,
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tiktok-access',
        'refresh_token' => 'tiktok-refresh',
    ]);

    return $account;
}

/**
 * @return array<string, mixed>
 */
function creatorInfoPayload(array $data = []): array
{
    return [
        'data' => [
            'creator_avatar_url' => 'https://p16.tiktokcdn.test/a.jpeg',
            'creator_username' => 'harbor.studio',
            'creator_nickname' => 'Harbor Studio',
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'],
            'comment_disabled' => false,
            'duet_disabled' => true,
            'stitch_disabled' => false,
            'max_video_post_duration_sec' => 300,
            ...$data,
        ],
        'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'log-1'],
    ];
}

test('it returns the creator info TikTok reports for the account', function () {
    [, $workspace] = ownerActingIn();
    $account = creatorInfoAccount($workspace);
    Http::fake([TIKTOK_CREATOR_INFO_URL => Http::response(creatorInfoPayload())]);

    test()->getJson(route('accounts.tiktok.creator-info', $account))
        ->assertOk()
        ->assertExactJson([
            'available' => true,
            'creator' => [
                'nickname' => 'Harbor Studio',
                'username' => 'harbor.studio',
                'privacy_levels' => [
                    ['value' => 'PUBLIC_TO_EVERYONE', 'label' => 'Everyone'],
                    ['value' => 'MUTUAL_FOLLOW_FRIENDS', 'label' => 'Friends'],
                    ['value' => 'SELF_ONLY', 'label' => 'Only me'],
                ],
                'comment_disabled' => false,
                'duet_disabled' => true,
                'stitch_disabled' => false,
                'max_video_duration_seconds' => 300,
            ],
        ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === TIKTOK_CREATOR_INFO_URL
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer tiktok-access'));
});

test('it meters each creator info call', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    [, $workspace] = ownerActingIn();
    $account = creatorInfoAccount($workspace);
    Http::fake([TIKTOK_CREATOR_INFO_URL => Http::response(creatorInfoPayload())]);

    test()->getJson(route('accounts.tiktok.creator-info', $account))->assertOk();

    expect(UsageEvent::query()
        ->where('workspace_id', $workspace->id)
        ->where('operation', UsageOperation::CREATOR_INFO)
        ->where('platform', Platform::TikTok->value)
        ->count())->toBe(1);
});

test('it drops privacy levels TikTok has not documented', function () {
    [, $workspace] = ownerActingIn();
    $account = creatorInfoAccount($workspace);
    Http::fake([TIKTOK_CREATOR_INFO_URL => Http::response(creatorInfoPayload([
        'privacy_level_options' => ['SELF_ONLY', 'SOMETHING_NEW'],
    ]))]);

    test()->getJson(route('accounts.tiktok.creator-info', $account))
        ->assertOk()
        ->assertJsonPath('creator.privacy_levels', [['value' => 'SELF_ONLY', 'label' => 'Only me']]);
});

test('it reports when TikTok will not let the creator post right now', function () {
    [, $workspace] = ownerActingIn();
    $account = creatorInfoAccount($workspace);
    Http::fake([TIKTOK_CREATOR_INFO_URL => Http::response([
        'data' => [],
        'error' => ['code' => 'spam_risk_too_many_posts', 'message' => 'too many posts', 'log_id' => 'log-2'],
    ])]);

    test()->getJson(route('accounts.tiktok.creator-info', $account))
        ->assertOk()
        ->assertExactJson([
            'available' => false,
            'message' => 'This TikTok account has reached its daily posting limit. Try again later.',
        ]);
});

test('it asks for a reconnect when the token can no longer be refreshed', function () {
    [, $workspace] = ownerActingIn();
    $account = creatorInfoAccount($workspace, ['token_expires_at' => now()->subMinute()]);
    Http::fake(['https://open.tiktokapis.com/v2/oauth/token/' => Http::response(['error' => 'invalid_grant'])]);

    test()->getJson(route('accounts.tiktok.creator-info', $account))
        ->assertOk()
        ->assertExactJson(['available' => false, 'message' => 'TikTok needs you to reconnect this account.']);
});

test('it reports a temporary failure when TikTok cannot be reached', function () {
    [, $workspace] = ownerActingIn();
    $account = creatorInfoAccount($workspace);
    Http::fake([TIKTOK_CREATOR_INFO_URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    $response = test()->getJson(route('accounts.tiktok.creator-info', $account))
        ->assertOk()
        ->assertJsonPath('available', false);

    expect($response->json('message'))->toBeString()->not->toContain('tiktok-access');
});

test('it never exposes the access token', function () {
    [, $workspace] = ownerActingIn();
    $account = creatorInfoAccount($workspace);
    Http::fake([TIKTOK_CREATOR_INFO_URL => Http::response(creatorInfoPayload())]);

    $body = test()->getJson(route('accounts.tiktok.creator-info', $account))->getContent();

    expect($body)->not->toContain('tiktok-access')
        ->and($body)->not->toContain('tiktok-refresh');
});

test('it 404s for an account on another platform', function () {
    [, $workspace] = ownerActingIn();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    Http::fake();

    test()->getJson(route('accounts.tiktok.creator-info', $account))->assertNotFound();

    Http::assertNothingSent();
});

test('it 404s for a tiktok account in another workspace', function () {
    ownerActingIn();
    $account = creatorInfoAccount(Workspace::factory()->create());
    Http::fake();

    test()->getJson(route('accounts.tiktok.creator-info', $account))->assertNotFound();

    Http::assertNothingSent();
});

test('it requires authentication', function () {
    $account = creatorInfoAccount(Workspace::factory()->create());

    test()->getJson(route('accounts.tiktok.creator-info', $account))->assertUnauthorized();
});
