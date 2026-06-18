<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

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

test('manual refresh returns fresh per-post payload with totals.likes', function () {
    Http::fake(['public.api.bsky.app/*' => Http::response(['posts' => [
        ['likeCount' => 4, 'repostCount' => 0, 'quoteCount' => 0, 'replyCount' => 0],
    ]])]);

    $account = ConnectedAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);

    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'remote_ids' => ['at://a/app.bsky.feed.post/1'],
    ]);

    $this->actingAs($this->user)
        ->postJson(route('posts.metrics.refresh', $post))
        ->assertOk()
        ->assertJsonPath('totals.likes', 4);
});

test('refresh endpoint 404s when metrics feature is disabled', function () {
    config(['metrics.enabled' => false]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'author_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('posts.metrics.refresh', $post))
        ->assertNotFound();
});
