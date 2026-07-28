<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Services\Messaging\Connectors\FacebookDirectMessageConnector;
use Illuminate\Support\Facades\Http;

test('facebook lists messenger conversations', function () {
    Http::fake([
        'graph.facebook.com/*/conversations*' => Http::response(['data' => [[
            'id' => 'fb-convo-1',
            'participants' => ['data' => [['id' => 'page-id', 'name' => 'My Page'], ['id' => 'psid-bob', 'name' => 'Bob']]],
            'messages' => ['data' => [
                ['id' => 'fbm-1', 'from' => ['id' => 'psid-bob', 'name' => 'Bob'], 'message' => 'yo', 'created_time' => now()->subHour()->toIso8601String()],
            ]],
        ]]]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page-id']);
    $result = app(FacebookDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'page-tok'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->conversations[0]->counterpartRemoteId)->toBe('psid-bob');
    expect($result->conversations[0]->messagingWindowExpiresAt)->not->toBeNull();
});

test('facebook send uses messaging_type RESPONSE', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'fb-sent-1'])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page-id']);
    $convo = Conversation::factory()->for($account, 'account')->create(['counterpart_remote_id' => 'psid-bob']);
    $result = app(FacebookDirectMessageConnector::class)->sendMessage($account, $convo, 'hi bob', ['access_token' => 'page-tok']);
    expect($result->remoteMessageId)->toBe('fb-sent-1');
    Http::assertSent(fn ($req) => data_get($req->data(), 'messaging_type') === 'RESPONSE');
});

test('facebook send is skipped once the messaging window has closed', function () {
    Http::fake();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page-id']);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'counterpart_remote_id' => 'psid-bob',
        'messaging_window_expires_at' => now()->subHour(),
    ]);

    $result = app(FacebookDirectMessageConnector::class)->sendMessage($account, $convo, 'hi bob', ['access_token' => 'page-tok']);

    expect($result->status)->toBe(\App\Enums\EngagementStatus::Unsupported);
    Http::assertNothingSent();
});

test('facebook maps 190 error to auth expired', function () {
    Http::fake(['graph.facebook.com/*/conversations*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 200)]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page-id']);
    $result = app(FacebookDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'page-tok'], null);
    expect($result->status)->toBe(\App\Enums\EngagementStatus::AuthExpired);
});
