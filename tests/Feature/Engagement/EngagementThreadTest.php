<?php

use App\Enums\ReplyStatus;
use App\Enums\WorkspaceRole;
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
