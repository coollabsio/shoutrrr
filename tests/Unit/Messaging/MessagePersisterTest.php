<?php

use App\Enums\MessageDirection;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Services\Messaging\Data\FetchedConversation;
use App\Services\Messaging\Data\FetchedMessage;
use App\Services\Messaging\MessagePersister;

function fetchedConvo(array $overrides = []): FetchedConversation
{
    return new FetchedConversation(
        remoteConversationId: $overrides['id'] ?? 'convo-1',
        counterpartHandle: '@alice',
        counterpartName: 'Alice',
        counterpartAvatarUrl: null,
        counterpartRemoteId: 'did:alice',
        messagingWindowExpiresAt: $overrides['window'] ?? null,
        messages: $overrides['messages'] ?? [
            new FetchedMessage('m1', MessageDirection::Inbound, 'did:alice', 'hi', [], now()->toImmutable()),
        ],
    );
}

test('persist inserts conversation and messages and returns inbound count', function () {
    $account = ConnectedAccount::factory()->create(['platform' => \App\Enums\Platform::Bluesky]);

    $inserted = app(MessagePersister::class)->persist($account, [fetchedConvo()]);

    expect($inserted)->toBe(1);
    $convo = Conversation::withoutGlobalScopes()->first();
    expect($convo->remote_conversation_id)->toBe('convo-1');
    expect($convo->unread_count)->toBe(1);
    expect($convo->last_message_preview)->toBe('hi');
    expect(DirectMessage::withoutGlobalScopes()->count())->toBe(1);
});

test('persist is idempotent on remote_message_id', function () {
    $account = ConnectedAccount::factory()->create(['platform' => \App\Enums\Platform::Bluesky]);
    $persister = app(MessagePersister::class);

    $persister->persist($account, [fetchedConvo()]);
    $second = $persister->persist($account, [fetchedConvo()]);

    expect($second)->toBe(0);
    expect(DirectMessage::withoutGlobalScopes()->count())->toBe(1);
});

test('persist copies meta window and does not count outbound as unread', function () {
    $account = ConnectedAccount::factory()->create(['platform' => \App\Enums\Platform::Instagram]);
    $window = now()->addHours(24)->toImmutable();

    app(MessagePersister::class)->persist($account, [fetchedConvo([
        'window' => $window,
        'messages' => [
            new FetchedMessage('in-1', MessageDirection::Inbound, 'igsid', 'hey', [], now()->toImmutable()),
            new FetchedMessage('out-1', MessageDirection::Outbound, 'us', 'hello back', [], now()->toImmutable()),
        ],
    ])]);

    $convo = Conversation::withoutGlobalScopes()->first();
    expect($convo->unread_count)->toBe(1);
    expect($convo->messaging_window_expires_at->timestamp)->toBe($window->timestamp);
});
