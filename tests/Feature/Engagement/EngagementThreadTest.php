<?php

use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
    $this->actingAs($this->user);
    $this->target = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $this->workspace->id]))
        ->create(['remote_id' => 'at://root']);
});

test('opening a thread returns the reply and marks it read', function (): void {
    $reply = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://r1',
        'parent_remote_id' => 'at://root',
        'read_at' => null,
    ]);

    $this->getJson(route('engagement.thread', $reply))
        ->assertOk()
        ->assertJsonStructure(['thread']);

    expect($reply->fresh()->read_at)->not->toBeNull();
});

test('thread replies include the published post remote id for platform links', function (): void {
    $account = ConnectedAccount::factory()->bluesky()->create([
        'workspace_id' => $this->workspace->id,
        'handle' => '@heyandras-testing.bsky.social',
    ]);
    $target = PostTarget::factory()
        ->for(Post::factory()->create(['workspace_id' => $this->workspace->id]))
        ->for($account, 'account')
        ->create([
            'platform' => Platform::Bluesky,
            'remote_id' => 'at://did:plc:heyandras/app.bsky.feed.post/3kabc',
        ]);
    $reply = PostTargetReply::factory()->for($target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
    ]);

    $this->getJson(route('engagement.thread', $reply))
        ->assertOk()
        ->assertJsonPath('thread.0.post_remote_id', 'at://did:plc:heyandras/app.bsky.feed.post/3kabc')
        ->assertJsonPath('thread.0.account_handle', '@heyandras-testing.bsky.social');
});

test('archive redirects for Inertia form submissions', function (): void {
    $reply = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->post(route('engagement.archive', $reply))->assertRedirect();

    expect($reply->fresh()->status)->toBe(ReplyStatus::Archived);
});

test('archive removes a reply from the inbox query', function (): void {
    $reply = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->postJson(route('engagement.archive', $reply))->assertNoContent();

    expect($reply->fresh()->status)->toBe(ReplyStatus::Archived);
});

test('mark read sets read_at', function (): void {
    $reply = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'read_at' => null,
    ]);

    $this->postJson(route('engagement.read', $reply))->assertNoContent();

    expect($reply->fresh()->read_at)->not->toBeNull();
});

test('opening a conversation returns only replies in the selected base reply thread', function (): void {
    $this->target->forceFill(['remote_id' => 'at://root-post'])->save();

    $base = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://base',
        'parent_remote_id' => 'at://root-post',
        'author_handle' => 'andras.dev',
        'text' => 'base inbound',
        'is_ours' => false,
        'read_at' => null,
        'remote_created_at' => now()->subMinutes(5),
    ]);
    $ourReply = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://ours',
        'parent_remote_id' => $base->remote_reply_id,
        'author_handle' => 'our.account',
        'text' => 'our response',
        'is_ours' => true,
        'remote_created_at' => now()->subMinutes(4),
    ]);
    $latest = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://child',
        'parent_remote_id' => $ourReply->remote_reply_id,
        'author_handle' => 'andras.dev',
        'text' => 'child inbound',
        'is_ours' => false,
        'read_at' => null,
        'remote_created_at' => now()->subMinutes(3),
    ]);
    PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://other-base',
        'parent_remote_id' => 'at://root-post',
        'author_handle' => 'andras.dev',
        'text' => 'other base same author',
        'is_ours' => false,
        'remote_created_at' => now()->subMinutes(2),
    ]);

    $this->getJson(route('engagement.thread', $latest))
        ->assertOk()
        ->assertJsonPath('thread.0.text', 'base inbound')
        ->assertJsonPath('thread.1.text', 'our response')
        ->assertJsonPath('thread.2.text', 'child inbound')
        ->assertJsonMissing(['text' => 'other base same author']);

    expect($base->fresh()->read_at)->not->toBeNull()
        ->and($latest->fresh()->read_at)->not->toBeNull();
});

test('archiving a conversation archives all inbound replies in the base reply thread', function (): void {
    $this->target->forceFill(['remote_id' => 'at://root-post'])->save();

    $first = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://base',
        'parent_remote_id' => 'at://root-post',
        'author_handle' => 'andras.dev',
        'is_ours' => false,
    ]);
    $second = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://child',
        'parent_remote_id' => 'at://base',
        'author_handle' => 'andras.dev',
        'is_ours' => false,
    ]);
    $other = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://other-base',
        'parent_remote_id' => 'at://root-post',
        'author_handle' => 'andras.dev',
        'is_ours' => false,
    ]);

    $this->postJson(route('engagement.archive', $second))->assertNoContent();

    expect($first->fresh()->status)->toBe(ReplyStatus::Archived)
        ->and($second->fresh()->status)->toBe(ReplyStatus::Archived)
        ->and($other->fresh()->status)->toBe(ReplyStatus::Pending);
});

test('mark read marks all inbound replies in the base reply thread', function (): void {
    $this->target->forceFill(['remote_id' => 'at://root-post'])->save();

    $first = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://base',
        'parent_remote_id' => 'at://root-post',
        'author_handle' => 'andras.dev',
        'read_at' => null,
        'is_ours' => false,
    ]);
    $second = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://child',
        'parent_remote_id' => 'at://base',
        'author_handle' => 'andras.dev',
        'read_at' => null,
        'is_ours' => false,
    ]);
    $other = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://other-base',
        'parent_remote_id' => 'at://root-post',
        'author_handle' => 'andras.dev',
        'read_at' => null,
        'is_ours' => false,
    ]);

    $this->postJson(route('engagement.read', $second))->assertNoContent();

    expect($first->fresh()->read_at)->not->toBeNull()
        ->and($second->fresh()->read_at)->not->toBeNull()
        ->and($other->fresh()->read_at)->toBeNull();
});

test('a parent_remote_id cycle is bounded and does not hang', function (): void {
    $a = PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://a',
        'parent_remote_id' => 'at://b',
    ]);
    PostTargetReply::factory()->for($this->target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'remote_reply_id' => 'at://b',
        'parent_remote_id' => 'at://a',
    ]);

    $response = $this->getJson(route('engagement.thread', $a))
        ->assertOk()
        ->assertJsonStructure(['thread']);

    // The walk terminates (no hang) and the result is bounded, not unbounded:
    // ancestors (A->B, capped by the visited-set) + self + direct children.
    expect($response->json('thread'))->toBeArray()
        ->and(count($response->json('thread')))->toBeLessThanOrEqual(4);
});
