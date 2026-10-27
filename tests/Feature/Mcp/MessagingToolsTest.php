<?php

use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\ArchiveConversationTool;
use App\Mcp\Tools\GetConversationTool;
use App\Mcp\Tools\ListConversationsTool;
use App\Mcp\Tools\MarkConversationReadTool;
use App\Mcp\Tools\ReplyToConversationTool;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Testing\TestResponse;

beforeEach(function (): void {
    config()->set('messages.enabled', true);

    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    bindTokenToWorkspace($this->user, $this->workspace);
});

function xConversation(Workspace $workspace, array $attributes = []): Conversation
{
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);

    return Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'remote_conversation_id' => 'c-1',
        'counterpart_handle' => '@friend',
        ...$attributes,
    ]);
}

/** @return array<string, mixed> */
function toolPayload(TestResponse $response): array
{
    $result = (fn (): array => $this->response->toArray())->call($response);

    return json_decode($result['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

function unscopedConversation(Conversation $conversation): Conversation
{
    return Conversation::query()->withoutGlobalScopes()->findOrFail($conversation->id);
}

test('list_conversations shows the inbox, or archived conversations on request', function (): void {
    $inbox = xConversation($this->workspace, ['counterpart_handle' => '@inbox_person']);
    xConversation($this->workspace, ['counterpart_handle' => '@archived_person', 'archived_at' => now()]);
    xConversation(Workspace::factory()->create(), ['counterpart_handle' => '@foreign_person']);

    ShoutrrrServer::actingAs($this->user)->tool(ListConversationsTool::class)
        ->assertOk()
        ->assertSee(['@inbox_person', $inbox->id])
        ->assertDontSee(['@archived_person', '@foreign_person']);

    ShoutrrrServer::actingAs($this->user)->tool(ListConversationsTool::class, ['archived' => true])
        ->assertOk()
        ->assertSee('@archived_person')
        ->assertDontSee(['@inbox_person', '@foreign_person']);
});

test('list_conversations honors limit', function (): void {
    xConversation($this->workspace, ['counterpart_handle' => '@newer', 'last_message_at' => now()]);
    xConversation($this->workspace, ['counterpart_handle' => '@older', 'last_message_at' => now()->subDay()]);

    ShoutrrrServer::actingAs($this->user)->tool(ListConversationsTool::class, ['limit' => 1])
        ->assertOk()
        ->assertSee('@newer')
        ->assertDontSee('@older');
});

test('get_conversation returns the conversation and its thread oldest first', function (): void {
    $conversation = xConversation($this->workspace);
    foreach (['second' => now()->subMinute(), 'first' => now()->subHour()] as $text => $at) {
        DirectMessage::query()->create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $conversation->id,
            'remote_message_id' => "m-{$text}",
            'direction' => MessageDirection::Inbound,
            'author_remote_id' => 'them',
            'text' => "{$text} message",
            'attachments' => [],
            'remote_created_at' => $at,
            'is_ours' => false,
        ]);
    }

    $response = ShoutrrrServer::actingAs($this->user)->tool(GetConversationTool::class, ['conversation_id' => $conversation->id]);

    $response->assertOk();
    $payload = toolPayload($response);
    expect($payload['conversation']['id'])->toBe($conversation->id)
        ->and(array_column($payload['messages'], 'text'))->toBe(['first message', 'second message']);
});

test('mark_conversation_read zeroes unread and stamps read_at', function (): void {
    $conversation = xConversation($this->workspace, ['unread_count' => 3]);

    ShoutrrrServer::actingAs($this->user)->tool(MarkConversationReadTool::class, ['conversation_id' => $conversation->id])
        ->assertOk();

    $conversation = unscopedConversation($conversation);
    expect($conversation->unread_count)->toBe(0)
        ->and($conversation->read_at)->not->toBeNull();
});

test('archive_conversation stamps archived_at', function (): void {
    $conversation = xConversation($this->workspace);

    ShoutrrrServer::actingAs($this->user)->tool(ArchiveConversationTool::class, ['conversation_id' => $conversation->id])
        ->assertOk()
        ->assertSee('"archived":true');

    expect(unscopedConversation($conversation)->archived_at)->not->toBeNull();
});

test('reply_to_conversation sends and records the message once confirmed', function (): void {
    Http::fake(['api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-1']], 201)]);
    $conversation = xConversation($this->workspace);

    ShoutrrrServer::actingAs($this->user)->tool(ReplyToConversationTool::class, [
        'conversation_id' => $conversation->id,
        'text' => 'hello there',
        'confirm' => true,
    ])->assertOk()->assertSee(['hello there', 'sent-1']);

    Http::assertSentCount(1);
    $sent = DirectMessage::query()->withoutGlobalScopes()->where('conversation_id', $conversation->id)->sole();
    expect($sent->text)->toBe('hello there')
        ->and($sent->is_ours)->toBeTrue()
        ->and($sent->our_remote_id)->toBe('sent-1');
});

test('reply_to_conversation refuses to send without confirm', function (): void {
    Http::fake();
    $conversation = xConversation($this->workspace);

    ShoutrrrServer::actingAs($this->user)->tool(ReplyToConversationTool::class, [
        'conversation_id' => $conversation->id,
        'text' => 'hello there',
    ])->assertHasErrors(['This will send a direct message to @friend.']);

    Http::assertNothingSent();
    expect(DirectMessage::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('reply_to_conversation reports a closed reply window without sending', function (): void {
    Http::fake();
    $conversation = xConversation($this->workspace, ['messaging_window_expires_at' => now()->subMinute()]);

    ShoutrrrServer::actingAs($this->user)->tool(ReplyToConversationTool::class, [
        'conversation_id' => $conversation->id,
        'text' => 'too late',
        'confirm' => true,
    ])->assertHasErrors(['The 24-hour reply window has closed.']);

    Http::assertNothingSent();
});

test('reply_to_conversation requires text or media', function (): void {
    Http::fake();
    $conversation = xConversation($this->workspace);

    ShoutrrrServer::actingAs($this->user)->tool(ReplyToConversationTool::class, [
        'conversation_id' => $conversation->id,
        'confirm' => true,
    ])->assertHasErrors();

    Http::assertNothingSent();
});

test('messaging tools reject a conversation from another workspace', function (string $tool, array $arguments): void {
    Http::fake();
    $foreign = xConversation(Workspace::factory()->create(), ['unread_count' => 2]);

    ShoutrrrServer::actingAs($this->user)->tool($tool, ['conversation_id' => $foreign->id, ...$arguments])
        ->assertHasErrors(['No conversation with that id exists in this workspace.']);

    $foreign = unscopedConversation($foreign);
    expect($foreign->unread_count)->toBe(2)
        ->and($foreign->archived_at)->toBeNull();
    Http::assertNothingSent();
})->with([
    'get_conversation' => [GetConversationTool::class, []],
    'mark_conversation_read' => [MarkConversationReadTool::class, []],
    'archive_conversation' => [ArchiveConversationTool::class, []],
    'reply_to_conversation' => [ReplyToConversationTool::class, ['text' => 'hi', 'confirm' => true]],
]);

test('messaging tools are unavailable when messages are disabled on the instance', function (string $tool): void {
    $conversation = xConversation($this->workspace);
    config()->set('messages.enabled', false);

    ShoutrrrServer::actingAs($this->user)->tool($tool, ['conversation_id' => $conversation->id, 'text' => 'hi', 'confirm' => true])
        ->assertHasErrors(['Direct messages are disabled on this instance.']);
})->with([
    ListConversationsTool::class,
    GetConversationTool::class,
    MarkConversationReadTool::class,
    ArchiveConversationTool::class,
    ReplyToConversationTool::class,
]);

test('messaging tools deny a user removed from the bound workspace', function (): void {
    Http::fake();
    $conversation = xConversation($this->workspace);
    WorkspaceMembership::query()->where('user_id', $this->user->id)->delete();

    ShoutrrrServer::actingAs($this->user)->tool(ReplyToConversationTool::class, [
        'conversation_id' => $conversation->id,
        'text' => 'hi',
        'confirm' => true,
    ])->assertHasErrors(['This connection is not bound to a workspace.']);

    ShoutrrrServer::actingAs($this->user)->tool(ListConversationsTool::class)
        ->assertHasErrors(['This connection is not bound to a workspace.']);

    Http::assertNothingSent();
});
