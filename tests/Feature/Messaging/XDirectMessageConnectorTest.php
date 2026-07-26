<?php

declare(strict_types=1);

use App\Enums\EngagementStatus;
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
    expect($result->status)->toBe(EngagementStatus::RateLimited);
    expect($result->retryAfterSeconds)->toBeGreaterThan(0);
});

// A conversation where only our own messages fall in the fetch window has no
// inbound sender to read the counterpart off, but X names 1:1 conversations
// `{userA}-{userB}`, so it is still recoverable — and then looked up by id.
test('x resolves the counterpart of an outbound-only conversation from the conversation id', function () {
    Http::fake([
        'api.twitter.com/2/dm_events*' => Http::response([
            'data' => [
                ['id' => 'e1', 'event_type' => 'MessageCreate', 'text' => 'you around?', 'sender_id' => '111', 'dm_conversation_id' => '111-222', 'created_at' => '2026-07-20T10:00:00Z'],
            ],
            'includes' => ['users' => [['id' => '111', 'username' => 'me', 'name' => 'Me']]],
            'meta' => [],
        ]),
        'api.twitter.com/2/users*' => Http::response([
            'data' => [['id' => '222', 'username' => 'bob', 'name' => 'Bob', 'profile_image_url' => 'https://x/b.jpg']],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '111']);
    $result = app(XDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->conversations[0]->counterpartRemoteId)->toBe('222');
    expect($result->conversations[0]->counterpartHandle)->toBe('@bob');
    expect($result->conversations[0]->counterpartName)->toBe('Bob');
    expect($result->conversations[0]->messages[0]->direction)->toBe(MessageDirection::Outbound);
});

test('x skips the user lookup when every counterpart is already expanded', function () {
    Http::fake([
        'api.twitter.com/2/dm_events*' => Http::response([
            'data' => [
                ['id' => 'e1', 'event_type' => 'MessageCreate', 'text' => 'hi', 'sender_id' => '999', 'dm_conversation_id' => '111-999', 'created_at' => '2026-07-20T10:00:00Z'],
            ],
            'includes' => ['users' => [['id' => '999', 'username' => 'alice', 'name' => 'Alice']]],
            'meta' => [],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '111']);
    $result = app(XDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);

    expect($result->conversations[0]->counterpartHandle)->toBe('@alice');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/users'));
});

test('x leaves a group conversation counterpart unresolved', function () {
    Http::fake([
        'api.twitter.com/2/dm_events*' => Http::response([
            'data' => [
                ['id' => 'e1', 'event_type' => 'MessageCreate', 'text' => 'hey all', 'sender_id' => '111', 'dm_conversation_id' => 'group-abc-def', 'created_at' => '2026-07-20T10:00:00Z'],
            ],
            'includes' => ['users' => []],
            'meta' => [],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '111']);
    $result = app(XDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);

    expect($result->conversations[0]->counterpartRemoteId)->toBeNull();
    expect($result->conversations[0]->counterpartHandle)->toBeNull();
});

test('x sendMessage posts to the conversation endpoint', function () {
    Http::fake(['api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-1']], 201)]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $convo = Conversation::factory()->for($account, 'account')->create(['remote_conversation_id' => 'c-1']);
    $result = app(XDirectMessageConnector::class)->sendMessage($account, $convo, 'yo', ['access_token' => 'tok']);
    expect($result->isOk())->toBeTrue();
    expect($result->remoteMessageId)->toBe('sent-1');
});
