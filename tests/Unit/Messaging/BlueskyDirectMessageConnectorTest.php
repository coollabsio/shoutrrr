<?php

declare(strict_types=1);

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Services\Messaging\Connectors\BlueskyDirectMessageConnector;
use Illuminate\Support\Facades\Http;

function blueskyCreds(): array
{
    return ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social']];
}

test('bluesky lists convos with proxy header and maps messages', function () {
    Http::fake([
        '*/xrpc/chat.bsky.convo.listConvos*' => Http::response(['convos' => [[
            'id' => 'convo-x', 'unreadCount' => 1,
            'members' => [['did' => 'did:me'], ['did' => 'did:alice', 'handle' => 'alice.bsky.social', 'displayName' => 'Alice', 'avatar' => 'https://a']],
        ]], 'cursor' => null]),
        '*/xrpc/chat.bsky.convo.getMessages*' => Http::response(['messages' => [
            ['id' => 'm1', 'text' => 'hello', 'sender' => ['did' => 'did:alice'], 'sentAt' => '2026-07-20T09:00:00Z'],
        ]]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky, 'remote_account_id' => 'did:me']);
    $result = app(BlueskyDirectMessageConnector::class)->fetchConversations($account, blueskyCreds(), null);

    expect($result->isOk())->toBeTrue();
    expect($result->conversations[0]->counterpartHandle)->toBe('@alice.bsky.social');
    expect($result->conversations[0]->messages[0]->text)->toBe('hello');

    Http::assertSent(fn ($req) => $req->hasHeader('atproto-proxy', 'did:web:api.bsky.chat#bsky_chat'));
});

test('bluesky sends a message', function () {
    Http::fake(['*/xrpc/chat.bsky.convo.sendMessage' => Http::response(['id' => 'sent-1'])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);
    $convo = Conversation::factory()->for($account, 'account')->create(['remote_conversation_id' => 'convo-x']);
    $result = app(BlueskyDirectMessageConnector::class)->sendMessage($account, $convo, 'hey', blueskyCreds());
    expect($result->isOk())->toBeTrue();
    expect($result->remoteMessageId)->toBe('sent-1');
});

test('bluesky returns unsupported for oauth dpop sessions', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);
    $creds = ['session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social', 'dpop_private_jwk' => ['kty' => 'EC']]];
    $result = app(BlueskyDirectMessageConnector::class)->fetchConversations($account, $creds, null);
    expect($result->status)->toBe(EngagementStatus::Unsupported);
});
