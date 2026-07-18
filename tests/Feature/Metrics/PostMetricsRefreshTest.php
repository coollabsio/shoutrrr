<?php

declare(strict_types=1);

use App\Enums\MetricsStatus;
use App\Enums\Platform;
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
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'role' => WorkspaceRole::Member,
    ]);
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    Context::add('workspace_id', $this->workspace->id);
    $this->post = Post::factory()->for($this->workspace)->create(['author_id' => $this->user->id]);
});

it('one-shot refreshes a published target that has aged past automatic polling', function (): void {
    Http::fake(['public.api.bsky.app/*' => Http::response(['posts' => [
        ['likeCount' => 9, 'repostCount' => 2, 'quoteCount' => 0, 'replyCount' => 1],
    ]])]);

    $account = ConnectedAccount::factory()->for($this->workspace)->bluesky()->create();
    $target = PostTarget::factory()->for($this->post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'remote_ids' => ['at://a/app.bsky.feed.post/1'],
        // Aged well past the 168h hard stop — automatic polling would skip it.
        'posted_at' => now()->subDays(30),
        'metrics_captured_at' => now()->subDays(10),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('posts.metrics.refresh', $this->post))
        ->assertOk()
        ->assertJsonStructure(['supported', 'captured_at', 'totals', 'targets']);

    $fresh = $target->fresh();
    expect($fresh->likes)->toBe(9)
        ->and($fresh->metrics_status)->toBe(MetricsStatus::Ok)
        ->and($fresh->metrics_captured_at->gt(now()->subMinute()))->toBeTrue();
});

it('rejects a refresh for a post outside the caller workspace', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    $foreignPost = Post::factory()->for($otherWorkspace)->create();

    $this->actingAs($this->user)
        ->postJson(route('posts.metrics.refresh', $foreignPost))
        ->assertNotFound();
});

it('is unreachable when the metrics feature is disabled instance-wide', function (): void {
    config(['metrics.enabled' => false]);

    $this->actingAs($this->user)
        ->postJson(route('posts.metrics.refresh', $this->post))
        ->assertNotFound();
});

it('rate limits repeated manual refreshes, since dispatchSync bypasses the queued job\'s own throttle', function (): void {
    $this->actingAs($this->user);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson(route('posts.metrics.refresh', $this->post));
    }

    $this->postJson(route('posts.metrics.refresh', $this->post))->assertStatus(429);
});
