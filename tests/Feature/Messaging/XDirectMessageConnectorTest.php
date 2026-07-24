<?php

declare(strict_types=1);

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Services\Messaging\Connectors\XDirectMessageConnector;
use Illuminate\Support\Facades\Http;

test('x fetchConversations groups dm events by conversation', function () {
    Http::fake([
        'api.twitter.com/2/dm_events*' => Http::response([
            'data' => [
                ['id' => 'e1', 'event_type' => 'MessageCreate', 'text' => 'hi', 'sender_id' => '999', 'dm_conversation_id' => 'c-1', 'created_at' => '2026-07-20T10:00:00Z'],
                ['id' => 'e2', 'event_type' => 'MessageCreate', 'text' => 'again', 'sender_id' => '999', 'dm_conversation_id' => 'c-1', 'created_at' => '2026-07-20T10:05:00Z'],
            ],
            'includes' => ['users' => [['id' => '999', 'username' => 'alice', 'name' => 'Alice', 'profile_image_url' => 'https://x/a.jpg']]],
            'meta' => [],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '123']);
    $result = app(XDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->conversations)->toHaveCount(1);
    expect($result->conversations[0]->remoteConversationId)->toBe('c-1');
    expect($result->conversations[0]->counterpartHandle)->toBe('@alice');
    expect($result->conversations[0]->messages)->toHaveCount(2);
    expect($result->conversations[0]->messages[0]->direction)->toBe(MessageDirection::Inbound);
});

test('x fetch maps 429 to rate limited', function () {
    Http::fake(['api.twitter.com/2/dm_events*' => Http::response('slow down', 429, ['x-rate-limit-reset' => (string) (time() + 60)])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $result = app(XDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);
    expect($result->status)->toBe(\App\Enums\EngagementStatus::RateLimited);
    expect($result->retryAfterSeconds)->toBeGreaterThan(0);
});

test('x sendMessage posts to the conversation endpoint', function () {
    Http::fake(['api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-1']], 201)]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $convo = Conversation::factory()->for($account, 'account')->create(['remote_conversation_id' => 'c-1']);
    $result = app(XDirectMessageConnector::class)->sendMessage($account, $convo, 'yo', ['access_token' => 'tok']);
    expect($result->isOk())->toBeTrue();
    expect($result->remoteMessageId)->toBe('sent-1');
});
