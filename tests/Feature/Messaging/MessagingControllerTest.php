<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('messages.enabled', true);

    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
});

test('index renders messages page with conversations', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
        'capabilities' => ['dm_enabled' => true],
    ]);
    Conversation::factory()->for($account, 'account')->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)->get('/messages')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('messages/index')
            ->loadDeferredProps(fn ($reload) => $reload->has('conversations')));
});

test('markRead zeroes unread and stamps read_at', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
        'capabilities' => ['dm_enabled' => true],
    ]);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id,
        'unread_count' => 3,
    ]);

    $this->actingAs($this->user)->post("/messages/{$convo->id}/read")->assertNoContent();

    expect($convo->refresh()->unread_count)->toBe(0);
    expect($convo->read_at)->not->toBeNull();
});

test('respond on x creates outgoing row and returns 201', function (): void {
    Http::fake(['api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-1']], 201)]);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'remote_conversation_id' => 'c-1',
    ]);

    $this->actingAs($this->user)->postJson("/messages/{$convo->id}/reply", ['text' => 'hello'])
        ->assertStatus(201)
        ->assertJsonPath('message.is_ours', true);

    expect(DirectMessage::withoutGlobalScopes()->where('conversation_id', $convo->id)->where('is_ours', true)->count())->toBe(1);
});

test('respond blocked when meta window closed returns non-422 error', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'capabilities' => ['dm_enabled' => true],
    ]);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'messaging_window_expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)->postJson("/messages/{$convo->id}/reply", ['text' => 'late'])
        ->assertStatus(409); // Unsupported window; NEVER 422
});
