<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Carbon;
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
});

/**
 * Fetch a page of the deferred/scroll `posts` prop the way the frontend does:
 * a partial Inertia request scoped to `posts`, optionally carrying a cursor and
 * the infinite-scroll append intent.
 */
function fetchPostsScrollPage(object $test, string $version, ?string $cursor = null): array
{
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'posts/index',
        'X-Inertia-Partial-Data' => 'posts',
    ];

    if ($cursor !== null) {
        $headers['X-Inertia-Infinite-Scroll-Merge-Intent'] = 'append';
    }

    $response = $test->actingAs($test->user)
        ->withHeaders($headers)
        ->get(route('posts.index', $cursor !== null ? ['cursor' => $cursor] : []));

    $response->assertOk();

    return $response->json();
}

test('posts infinite scroll walks every page without crashing on page 2', function () {
    // Mix scheduled and draft posts so the COALESCE(scheduled_at, created_at)
    // ordering is actually exercised (drafts fall back to created_at).
    $base = Carbon::parse('2026-01-01 00:00:00');
    for ($i = 0; $i < 45; $i++) {
        Post::factory()->for($this->workspace)->create([
            'author_id' => $this->user->id,
            'status' => $i % 2 === 0 ? PostStatus::Scheduled->value : PostStatus::Draft->value,
            'scheduled_at' => $i % 2 === 0 ? $base->copy()->addMinutes($i) : null,
            'created_at' => $base->copy()->addMinutes($i),
        ]);
    }

    $version = (string) app(HandleInertiaRequests::class)
        ->version(request());

    // Page 1 (the deferred initial load).
    $page1 = fetchPostsScrollPage($this, $version);
    expect($page1['props']['posts']['data'])->toHaveCount(20);
    $cursor = $page1['scrollProps']['posts']['nextPage'];
    expect($cursor)->toBeString();

    // Page 2 (infinite scroll) — this is the request that used to 500 with
    // "Undefined array key 0" because the raw orderBy produced no keyset.
    $page2 = fetchPostsScrollPage($this, $version, $cursor);
    expect($page2['props']['posts']['data'])->toHaveCount(20);
    expect($page2['mergeProps'] ?? [])->toContain('posts.data');
    $cursor = $page2['scrollProps']['posts']['nextPage'];
    expect($cursor)->toBeString();

    // Page 3 (final 5) — cursor exhausts cleanly with no next page.
    $page3 = fetchPostsScrollPage($this, $version, $cursor);
    expect($page3['props']['posts']['data'])->toHaveCount(5);
    expect($page3['scrollProps']['posts']['nextPage'])->toBeNull();

    // Every post reachable exactly once across the three pages.
    $ids = collect([$page1, $page2, $page3])
        ->flatMap(fn (array $p): array => array_column($p['props']['posts']['data'], 'id'));
    expect($ids)->toHaveCount(45)
        ->and($ids->unique())->toHaveCount(45);
});
