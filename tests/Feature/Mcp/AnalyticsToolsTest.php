<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\GetAnalyticsTool;
use App\Mcp\Tools\RefreshPostMetricsTool;
use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * @return array<string, mixed>
 */
function analyticsToolJson(TestResponse $response): array
{
    $payload = (fn (): array => $this->response->toArray())->call($response);

    return json_decode($payload['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
}

function publishedPostWithEngagement(Workspace $workspace, string $text, int $likes): Post
{
    $post = Post::factory()->for($workspace)->create([
        'base_text' => $text,
        'status' => PostStatus::Published,
        'published_at' => Date::now()->subDays(3),
    ]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'metrics_status' => MetricsStatus::Ok,
        'likes' => $likes,
        'comments' => 1,
        'reposts' => 0,
    ]);

    return $post;
}

test('get_analytics returns the bound workspace summary, accounts, and ranked posts', function (): void {
    [$user, $workspace] = mcpOwner();
    $account = ConnectedAccount::factory()->for($workspace)->create(['handle' => 'mine']);
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now()->subDays(10), 'followers' => 100]);
    AccountMetric::factory()->create(['connected_account_id' => $account->id, 'captured_at' => Date::now(), 'followers' => 130]);
    $post = publishedPostWithEngagement($workspace, 'Launch day', 4);

    $other = Workspace::factory()->create();
    $foreignAccount = ConnectedAccount::factory()->for($other)->create(['handle' => 'foreign']);
    AccountMetric::factory()->create(['connected_account_id' => $foreignAccount->id, 'captured_at' => Date::now(), 'followers' => 9999]);
    publishedPostWithEngagement($other, 'Foreign post', 50);

    $response = ShoutrrrServer::actingAs($user)->tool(GetAnalyticsTool::class, ['days' => 30]);
    $response->assertOk();
    $data = analyticsToolJson($response);

    expect($data['range_days'])->toBe(30)
        ->and($data['summary']['account_count'])->toBe(1)
        ->and($data['summary']['followers'])->toBe(['value' => 130, 'delta' => 30])
        ->and($data['summary']['engagement']['value'])->toBe(5)
        ->and($data['summary']['posts']['value'])->toBe(1)
        ->and(array_column($data['accounts'], 'handle'))->toBe(['mine'])
        ->and(array_column($data['posts'], 'id'))->toBe([$post->id])
        ->and($data['comparison']['top'][0]['title'])->toBe('Launch day')
        ->and($data['comparison']['top'][0]['engagement'])->toBe(5);
});

test('get_analytics clamps the window like the Classic page', function (): void {
    [$user] = mcpOwner();

    $tooSmall = ShoutrrrServer::actingAs($user)->tool(GetAnalyticsTool::class, ['days' => 1]);
    $default = ShoutrrrServer::actingAs($user)->tool(GetAnalyticsTool::class, []);

    expect(analyticsToolJson($tooSmall)['range_days'])->toBe(7)
        ->and(analyticsToolJson($default)['range_days'])->toBe(90);
});

test('get_analytics errors when metrics are disabled instance-wide', function (): void {
    [$user] = mcpOwner();
    config(['metrics.enabled' => false]);

    ShoutrrrServer::actingAs($user)->tool(GetAnalyticsTool::class, [])
        ->assertHasErrors(['Analytics are disabled on this instance.']);
});

test('get_analytics is denied once the user leaves the bound workspace', function (): void {
    [$user, $workspace] = mcpOwner();
    WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->delete();

    ShoutrrrServer::actingAs($user->fresh())->tool(GetAnalyticsTool::class, [])
        ->assertHasErrors(['not bound to a workspace']);
});

test('refresh_post_metrics captures fresh counts and returns the post metrics', function (): void {
    Http::fake(['public.api.bsky.app/*' => Http::response(['posts' => [
        ['likeCount' => 9, 'repostCount' => 2, 'quoteCount' => 0, 'replyCount' => 1],
    ]])]);
    [$user, $workspace] = mcpOwner();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $account = ConnectedAccount::factory()->for($workspace)->bluesky()->create();
    $target = PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://a/app.bsky.feed.post/1',
        'remote_ids' => ['at://a/app.bsky.feed.post/1'],
        'posted_at' => Date::now()->subDays(30),
        'metrics_captured_at' => Date::now()->subDays(10),
    ]);

    $response = ShoutrrrServer::actingAs($user)->tool(RefreshPostMetricsTool::class, ['post_id' => $post->id]);
    $response->assertOk();
    $data = analyticsToolJson($response);

    expect($data['post_id'])->toBe($post->id)
        ->and($data['totals'])->toBe(['likes' => 9, 'comments' => 1, 'reposts' => 2])
        ->and($data['targets'][0]['id'])->toBe($target->id)
        ->and($data['targets'][0]['status'])->toBe(MetricsStatus::Ok->value)
        ->and($target->fresh()->likes)->toBe(9);
});

test('refresh_post_metrics rejects a post from another workspace without touching it', function (): void {
    Http::fake();
    [$user] = mcpOwner();
    $foreignPost = Post::factory()->for(Workspace::factory())->create();
    PostTarget::factory()->for($foreignPost)->create([
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'remote_id' => 'at://a/app.bsky.feed.post/2',
    ]);

    ShoutrrrServer::actingAs($user)->tool(RefreshPostMetricsTool::class, ['post_id' => $foreignPost->id])
        ->assertHasErrors(['No post with that id exists in this workspace.']);

    Http::assertNothingSent();
});

test('refresh_post_metrics errors when metrics are disabled instance-wide', function (): void {
    [$user, $workspace] = mcpOwner();
    $post = Post::factory()->for($workspace)->create();
    config(['metrics.enabled' => false]);

    ShoutrrrServer::actingAs($user)->tool(RefreshPostMetricsTool::class, ['post_id' => $post->id])
        ->assertHasErrors(['Post metrics are disabled on this instance.']);
});

test('refresh_post_metrics allows ten refreshes a minute per user', function (): void {
    [$user, $workspace] = mcpOwner();
    $post = Post::factory()->for($workspace)->create();

    for ($i = 0; $i < 10; $i++) {
        ShoutrrrServer::actingAs($user)->tool(RefreshPostMetricsTool::class, ['post_id' => $post->id])->assertOk();
    }

    ShoutrrrServer::actingAs($user)->tool(RefreshPostMetricsTool::class, ['post_id' => $post->id])
        ->assertHasErrors(['Too many metric refreshes.']);
});
