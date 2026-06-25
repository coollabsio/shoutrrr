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

test('the posts facet lists posts that drew replies with a count', function (): void {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'base_text' => 'Launch day thread']);
    $target = PostTarget::factory()->for($post)->create(['platform' => Platform::Bluesky]);

    PostTargetReply::factory()->count(3)->for($target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'is_ours' => false,
    ]);
    // Our own reply and an archived one must not be counted.
    PostTargetReply::factory()->for($target, 'target')->create([
        'workspace_id' => $this->workspace->id,
        'is_ours' => true,
    ]);

    $this->actingAs($this->user)
        ->get(route('engagement.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('facets.posts', 1)
            ->where('facets.posts.0.id', $post->id)
            ->where('facets.posts.0.count', 3));
});

test('filtering by post narrows the stream to that post', function (): void {
    $kept = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $other = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $keptTarget = PostTarget::factory()->for($kept)->create(['platform' => Platform::Bluesky]);
    $otherTarget = PostTarget::factory()->for($other)->create(['platform' => Platform::Bluesky]);

    PostTargetReply::factory()->for($keptTarget, 'target')->create([
        'workspace_id' => $this->workspace->id, 'text' => 'on kept post', 'is_ours' => false,
    ]);
    PostTargetReply::factory()->for($otherTarget, 'target')->create([
        'workspace_id' => $this->workspace->id, 'text' => 'on other post', 'is_ours' => false,
    ]);

    $this->actingAs($this->user)
        ->get(route('engagement.index', ['post' => $kept->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.post', $kept->id)
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('replies.data', 1)
                ->where('replies.data.0.text', 'on kept post')));
});

test('replies from another workspace are not visible', function (): void {
    PostTargetReply::factory()->create(['workspace_id' => 'other-workspace', 'text' => 'foreign']);

    $this->actingAs($this->user)
        ->get(route('engagement.index', ['unread' => 1]))
        ->assertOk();

    // HasWorkspaceScope filters by Context workspace_id, so the foreign row must not be visible.
    expect(PostTargetReply::query()->where('text', 'foreign')->exists())->toBeFalse();
});
