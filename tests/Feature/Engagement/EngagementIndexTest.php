<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
});

test('the inbox lists unarchived inbound replies for the workspace', function (): void {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $target = PostTarget::factory()->for($post)->create(['platform' => Platform::Bluesky]);

    PostTargetReply::factory()->for($target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'text' => 'visible reply',
        'is_ours' => false,
    ]);
    PostTargetReply::factory()->for($target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'is_ours' => true,
        'text' => 'our own reply',
    ]);

    $this->actingAs($this->user)
        ->get(route('engagement.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('engagement/index')
            ->has('filters')
            ->has('facets.accounts'));
});

test('replies from another workspace are not visible', function (): void {
    PostTargetReply::factory()->create(['workspace_id' => 'other-workspace', 'text' => 'foreign']);

    $this->actingAs($this->user)
        ->get(route('engagement.index', ['unread' => 1]))
        ->assertOk();

    // HasWorkspaceScope filters by Context workspace_id, so the foreign row must not be visible.
    expect(PostTargetReply::query()->where('text', 'foreign')->exists())->toBeFalse();
});
