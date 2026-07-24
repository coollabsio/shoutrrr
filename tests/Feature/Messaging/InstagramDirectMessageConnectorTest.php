<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Services\Messaging\Connectors\InstagramDirectMessageConnector;
use Illuminate\Support\Facades\Http;

test('instagram maps conversations and sets 24h window from latest inbound', function () {
    config()->set('messages.meta_window_hours', 24);
    $inbound = now()->subHours(2);
    Http::fake([
        'graph.facebook.com/*/conversations*' => Http::response(['data' => [[
            'id' => 'ig-convo-1',
            'participants' => ['data' => [['id' => 'me-igid', 'username' => 'mybiz'], ['id' => 'igsid-alice', 'username' => 'alice']]],
            'messages' => ['data' => [
                ['id' => 'igm-1', 'from' => ['id' => 'igsid-alice', 'username' => 'alice'], 'message' => 'hi there', 'created_time' => $inbound->toIso8601String()],
            ]],
        ]]]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'me-igid']);
    $result = app(InstagramDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);

    expect($result->isOk())->toBeTrue();
    $convo = $result->conversations[0];
    expect($convo->counterpartHandle)->toBe('@alice');
    expect($convo->counterpartRemoteId)->toBe('igsid-alice');
    expect($convo->messagingWindowExpiresAt)->not->toBeNull();
    expect($convo->messagingWindowExpiresAt->timestamp)->toBe($inbound->copy()->addHours(24)->timestamp);
});

test('instagram send posts recipient IGSID', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'ig-sent-1'])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'me-igid']);
    $convo = Conversation::factory()->for($account, 'account')->create(['counterpart_remote_id' => 'igsid-alice']);
    $result = app(InstagramDirectMessageConnector::class)->sendMessage($account, $convo, 'hello', ['access_token' => 'tok']);
    expect($result->isOk())->toBeTrue();
    expect($result->remoteMessageId)->toBe('ig-sent-1');
    Http::assertSent(fn ($req) => data_get($req->data(), 'recipient.id') === 'igsid-alice');
});

test('instagram maps 190 error to auth expired', function () {
    Http::fake(['graph.facebook.com/*/conversations*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 200)]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'me-igid']);
    $result = app(InstagramDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);
    expect($result->status)->toBe(\App\Enums\EngagementStatus::AuthExpired);
});

test('instagram send is skipped once the messaging window has closed', function () {
    Http::fake();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'me-igid']);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'counterpart_remote_id' => 'igsid-alice',
        'messaging_window_expires_at' => now()->subHour(),
    ]);

    $result = app(InstagramDirectMessageConnector::class)->sendMessage($account, $convo, 'hello', ['access_token' => 'tok']);

    expect($result->status)->toBe(\App\Enums\EngagementStatus::Unsupported);
    Http::assertNothingSent();
});
