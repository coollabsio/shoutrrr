<?php

use App\Dto\Messaging\FetchedConversation;
use App\Dto\Messaging\FetchedMessage;
use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Services\Messaging\MessagePersister;
use Carbon\CarbonImmutable;

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
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);

    $inserted = app(MessagePersister::class)->persist($account, [fetchedConvo()]);

    expect($inserted)->toBe(1);
    $convo = Conversation::withoutGlobalScopes()->first();
    expect($convo->remote_conversation_id)->toBe('convo-1');
    expect($convo->unread_count)->toBe(1);
    expect($convo->last_message_preview)->toBe('hi');
    expect(DirectMessage::withoutGlobalScopes()->count())->toBe(1);
});

test('persist is idempotent on remote_message_id', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Bluesky]);
    $persister = app(MessagePersister::class);

    $persister->persist($account, [fetchedConvo()]);
    $second = $persister->persist($account, [fetchedConvo()]);

    expect($second)->toBe(0);
    expect(DirectMessage::withoutGlobalScopes()->count())->toBe(1);
});

test('persist copies meta window and does not count outbound as unread', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram]);
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

test('persist preserves counterpart fields when a re-poll returns them null', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $persister = app(MessagePersister::class);

    $persister->persist($account, [fetchedConvo()]);

    // Simulate an outbound-only X re-poll: the connector derives the
    // counterpart from inbound senders, so it resolves to null when only our
    // own dm_event came back.
    $persister->persist($account, [new FetchedConversation(
        remoteConversationId: 'convo-1',
        counterpartHandle: null,
        counterpartName: null,
        counterpartAvatarUrl: null,
        counterpartRemoteId: null,
        messagingWindowExpiresAt: null,
        messages: [
            new FetchedMessage('m2', MessageDirection::Outbound, 'us', 'thanks', [], now()->toImmutable()),
        ],
    )]);

    $convo = Conversation::withoutGlobalScopes()->first();
    expect($convo->counterpart_handle)->toBe('@alice');
    expect($convo->counterpart_name)->toBe('Alice');
    expect($convo->counterpart_remote_id)->toBe('did:alice');
});

test('a resync does not wipe the attachments recorded for a message we sent', function (): void {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $conversation = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $account->workspace_id,
        'platform' => Platform::X,
        'remote_conversation_id' => 'c-1',
    ]);

    // What MessagingController::respond() wrote for an outbound message.
    $ours = DirectMessage::withoutGlobalScopes()->create([
        'workspace_id' => $account->workspace_id,
        'conversation_id' => $conversation->id,
        'remote_message_id' => 'evt-1',
        'direction' => MessageDirection::Outbound,
        'author_remote_id' => $account->remote_account_id,
        'text' => 'look',
        'attachments' => [['kind' => 'image', 'url' => '/x.jpg', 'mime' => 'image/jpeg', 'alt_text' => null]],
        'remote_created_at' => now(),
        'is_ours' => true,
    ]);

    // The next fetch returns the same event; no connector parses attachments,
    // so it arrives with an empty list.
    app(MessagePersister::class)->persist($account, [new FetchedConversation(
        remoteConversationId: 'c-1',
        counterpartHandle: '@them',
        counterpartName: 'Them',
        counterpartAvatarUrl: null,
        counterpartRemoteId: 'them-1',
        messagingWindowExpiresAt: null,
        messages: [new FetchedMessage(
            remoteMessageId: 'evt-1',
            direction: MessageDirection::Outbound,
            authorRemoteId: $account->remote_account_id,
            text: 'look',
            attachments: [],
            remoteCreatedAt: CarbonImmutable::now(),
        )],
    )]);

    expect($ours->refresh()->attachments)->toHaveCount(1);
});
