<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\MessageListItem;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('messages.enabled', true);
    Storage::fake('public');

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

test('respond on x attaches media and stores a render record on the row', function (): void {
    Http::fake([
        'api.x.com/2/media/upload' => Http::response(['data' => ['id' => 'media-99']], 200),
        'api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-2']], 201),
    ]);

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

    Storage::disk('public')->put('media/dm.jpg', 'bytes');
    $media = PostMedia::factory()->create([
        'workspace_id' => $this->workspace->id,
        'path' => 'media/dm.jpg',
        'alt_text' => 'a cat',
    ]);

    $this->actingAs($this->user)
        ->postJson("/messages/{$convo->id}/reply", ['text' => 'look', 'media' => [$media->id]])
        ->assertStatus(201);

    $row = DirectMessage::withoutGlobalScopes()->where('conversation_id', $convo->id)->where('is_ours', true)->sole();

    // The media is claimed by the message, so media:prune-uploads leaves it
    // alone and the bubble keeps rendering it.
    expect($media->refresh()->direct_message_id)->toBe($row->id);

    $view = MessageListItem::make($row->load('media'));
    expect($view['attachments'])->toHaveCount(1);
    expect($view['attachments'][0]['kind'])->toBe('image');
    expect($view['attachments'][0]['mime'])->toBe('image/jpeg');
    expect($view['attachments'][0]['alt_text'])->toBe('a cat');
    expect($view['attachments'][0]['url'])->toBeString();
});

test('respond accepts a media-only message with no text', function (): void {
    Http::fake([
        'api.x.com/2/media/upload' => Http::response(['data' => ['id' => 'media-99']], 200),
        'api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-3']], 201),
    ]);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'tok']);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'remote_conversation_id' => 'c-1',
    ]);

    Storage::disk('public')->put('media/dm.jpg', 'bytes');
    $media = PostMedia::factory()->create(['workspace_id' => $this->workspace->id, 'path' => 'media/dm.jpg']);

    $this->actingAs($this->user)
        ->postJson("/messages/{$convo->id}/reply", ['media' => [$media->id]])
        ->assertStatus(201);
});

test('respond rejects a message with neither text nor media', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
    ]);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);

    $this->actingAs($this->user)->postJson("/messages/{$convo->id}/reply", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');
});

test('respond rejects media on bluesky, whose DM lexicon has no media embed', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
        'capabilities' => ['dm_enabled' => true],
    ]);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
    ]);
    $media = PostMedia::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->postJson("/messages/{$convo->id}/reply", ['text' => 'hi', 'media' => [$media->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('media');
});

test('respond rejects media belonging to another workspace', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
    ]);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);
    $foreign = PostMedia::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    $this->actingAs($this->user)
        ->postJson("/messages/{$convo->id}/reply", ['text' => 'hi', 'media' => [$foreign->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('media.0');
});

test('respond never claims media already owned by a post in the same workspace', function (): void {
    Http::fake([
        'api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-9']], 201),
    ]);

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
        'remote_conversation_id' => 'c-9',
    ]);

    // Media already belonging to a post: the client must not be able to hijack
    // or reorder it by naming its id in a DM reply.
    $owned = PostMedia::factory()->create([
        'workspace_id' => $this->workspace->id,
        'post_id' => Post::factory()->create(['workspace_id' => $this->workspace->id])->id,
        'position' => 3,
    ]);

    $this->actingAs($this->user)
        ->postJson("/messages/{$convo->id}/reply", ['text' => 'hi', 'media' => [$owned->id]])
        ->assertStatus(201);

    $row = DirectMessage::withoutGlobalScopes()->where('conversation_id', $convo->id)->where('is_ours', true)->sole();

    // The owned media keeps its post ownership and position, and the sent DM
    // claims nothing.
    $owned->refresh();
    expect($owned->direct_message_id)->toBeNull();
    expect($owned->position)->toBe(3);
    expect(PostMedia::withoutGlobalScopes()->where('direct_message_id', $row->id)->count())->toBe(0);
});

test('respond persists the delivered attachment and claims its media when the text half of a meta send fails', function (): void {
    Http::fake(['graph.facebook.com/*/messages' => Http::sequence()
        ->push(['message_id' => 'fb-attachment-1'])
        ->push(['error' => ['code' => 100, 'message' => 'bad text']], 400)]);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'capabilities' => ['dm_enabled' => true],
        'remote_account_id' => 'page-id',
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'page-tok',
    ]);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'counterpart_remote_id' => 'psid-bob',
    ]);

    Storage::disk('public')->put('media/dm.jpg', 'bytes');
    $media = PostMedia::factory()->create(['workspace_id' => $this->workspace->id, 'path' => 'media/dm.jpg']);

    $this->actingAs($this->user)
        ->postJson("/messages/{$convo->id}/reply", ['text' => 'caption', 'media' => [$media->id]])
        ->assertStatus(502)
        ->assertJsonPath('status', 'failed');

    // Meta cannot recall the delivered attachment, so it must be persisted and
    // claimed locally even though the overall request reports a failure —
    // otherwise it sits orphaned until media:prune-uploads deletes it, or the
    // user resends it as a duplicate.
    $row = DirectMessage::withoutGlobalScopes()->where('conversation_id', $convo->id)->where('is_ours', true)->sole();
    expect($row->remote_message_id)->toBe('fb-attachment-1');
    expect($row->text)->toBe('');
    expect($media->refresh()->direct_message_id)->toBe($row->id);
});

test('respond rejects media already claimed by another sent direct message', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'capabilities' => ['dm_enabled' => true],
    ]);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);
    $claimed = PostMedia::factory()->create([
        'workspace_id' => $this->workspace->id,
        'direct_message_id' => DirectMessage::factory()->for($convo)->create(['workspace_id' => $this->workspace->id])->id,
    ]);

    $this->actingAs($this->user)
        ->postJson("/messages/{$convo->id}/reply", ['text' => 'hi', 'media' => [$claimed->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('media.0');
});
