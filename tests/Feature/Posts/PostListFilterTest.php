<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Pagination\Cursor;
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

function makePost(Workspace $w, User $u, PostStatus $status): Post
{
    return Post::factory()->for($w)->create([
        'author_id' => $u->id,
        'status' => $status->value,
    ]);
}

it('lists workspace posts and excludes deleted', function (): void {
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Deleted);

    $this->actingAs($this->user)
        ->get(route('posts.index'))
        ->assertInertia(fn ($page) => $page
            ->component('posts/index')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.status', 'draft')));
});

it('filters by status tab', function (): void {
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Scheduled);

    $this->actingAs($this->user)
        ->get(route('posts.index', ['status' => 'scheduled']))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.status', 'scheduled')));
});

it('exposes per-status tab counts that exclude deleted', function (): void {
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Draft);
    makePost($this->workspace, $this->user, PostStatus::Scheduled);
    makePost($this->workspace, $this->user, PostStatus::Deleted);

    $this->actingAs($this->user)
        ->get(route('posts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('counts.all', 3)
            ->where('counts.draft', 2)
            ->where('counts.scheduled', 1)
            ->where('counts.published', 0));
});

it('filters by text query on base_text', function (): void {
    Post::factory()->for($this->workspace)->create(['author_id' => $this->user->id, 'base_text' => 'launch announcement']);
    Post::factory()->for($this->workspace)->create(['author_id' => $this->user->id, 'base_text' => 'weekly recap']);

    $this->actingAs($this->user)
        ->get(route('posts.index', ['q' => 'launch']))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('posts.data', 1)
                ->where('posts.data.0.base_text', 'launch announcement')));
});

it('orders the all tab by the published time for published posts', function (): void {
    $base = now()->subDays(10);

    Post::factory()->for($this->workspace)->create([
        'author_id' => $this->user->id,
        'base_text' => 'older direct post',
        'status' => PostStatus::Published->value,
        'published_at' => $base,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Post::factory()->for($this->workspace)->create([
        'author_id' => $this->user->id,
        'base_text' => 'newer direct post',
        'status' => PostStatus::Published->value,
        'published_at' => $base->copy()->addDay(),
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $this->actingAs($this->user)
        ->get(route('posts.index'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('posts.data.0.base_text', 'newer direct post')
                ->where('posts.data.1.base_text', 'older direct post')));
});

it('cursor paginates more than one page of posts', function (): void {
    $base = now()->subHour();

    for ($i = 0; $i < 25; $i++) {
        Post::factory()->for($this->workspace)->create([
            'author_id' => $this->user->id,
            'base_text' => "Imported post {$i}",
            'created_at' => $base->copy()->addMinute($i),
            'updated_at' => $base->copy()->addMinute($i),
        ]);
    }

    $initialPage = $this->actingAs($this->user)
        ->get(route('posts.index'))
        ->assertOk()
        ->inertiaPage();

    $headers = [
        'X-Inertia-Version' => $initialPage['version'],
        'X-Inertia-Partial-Component' => 'posts/index',
        'X-Inertia-Partial-Data' => 'posts',
    ];

    $firstPage = $this->actingAs($this->user)
        ->get(route('posts.index'), $headers)
        ->assertOk()
        ->inertiaProps('posts');

    expect($firstPage['data'])->toHaveCount(20);
    expect($firstPage['next_page_url'])->toBeString()->not->toBe('');

    parse_str(parse_url($firstPage['next_page_url'], PHP_URL_QUERY) ?: '', $query);

    $cursor = Cursor::fromEncoded($query['cursor'] ?? null);

    expect($cursor?->parameter('list_sort_at'))->toBeString()->not->toBe('');

    $secondPage = $this->actingAs($this->user)
        ->get($firstPage['next_page_url'], $headers)
        ->assertOk()
        ->inertiaProps('posts');

    expect($secondPage['data'])->toHaveCount(5);
});
