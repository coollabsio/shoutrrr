<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;

/**
 * A workspace member whose current workspace + request Context are set, so
 * post route-model binding resolves and the PostPolicy passes.
 *
 * @return array{0: User, 1: Workspace}
 */
function duplicateMember(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    return [$user, $workspace];
}

/**
 * A published post with one image (real fake file) and one published target
 * carrying a per-account content override that references the media.
 */
function publishedPostWithMediaAndTarget(Workspace $workspace, User $user): Post
{
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id,
        'segments' => ['Hello world'],
        'base_text' => 'Hello world',
        'mentions' => [],
        'status' => PostStatus::Published->value,
        'published_at' => now(),
        'auto_repost' => true,
    ]);

    Storage::disk('public')->put('media/original.jpg', 'IMG');
    $media = PostMedia::factory()->for($workspace)->create([
        'post_id' => $post->id,
        'disk' => 'public',
        'path' => 'media/original.jpg',
        'position' => 0,
    ]);

    PostTarget::factory()->published()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'content_override' => ['text' => 'Custom', 'media_ids' => [$media->id]],
    ]);

    return $post->load('media', 'targets');
}

beforeEach(function (): void {
    Storage::fake('public');
    [$this->user, $this->workspace] = duplicateMember();
});

test('duplicating a published post creates a reset draft copy', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);

    $response = $this->actingAs($this->user)->post(route('posts.duplicate', $post));

    $draft = Post::query()->where('status', PostStatus::Draft->value)->firstOrFail();

    $response->assertRedirect(route('posts.show', $draft));
    expect($draft->id)->not->toBe($post->id)
        ->and($draft->workspace_id)->toBe($this->workspace->id)
        ->and($draft->segments)->toBe(['Hello world'])
        ->and($draft->auto_repost)->toBeTrue()
        ->and($draft->scheduled_at)->toBeNull()
        ->and($draft->published_at)->toBeNull();
});

test('cloned media is a new file that survives deleting the original post', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);

    $this->actingAs($this->user)->post(route('posts.duplicate', $post));

    $draft = Post::query()->where('status', PostStatus::Draft->value)->firstOrFail();
    $copy = $draft->media()->firstOrFail();

    expect($copy->path)->not->toBe('media/original.jpg')
        ->and(Storage::disk('public')->exists($copy->path))->toBeTrue();

    $post->media()->firstOrFail()->delete();

    expect(Storage::disk('public')->exists($copy->path))->toBeTrue();
});

test('cloned target is pending with overrides preserved and media ids remapped', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);

    $this->actingAs($this->user)->post(route('posts.duplicate', $post));

    $draft = Post::query()->where('status', PostStatus::Draft->value)->firstOrFail();
    $target = $draft->targets()->firstOrFail();
    $newMedia = $draft->media()->firstOrFail();

    expect($target->status)->toBe(PostTargetStatus::Pending)
        ->and($target->remote_id)->toBeNull()
        ->and($target->posted_at)->toBeNull()
        ->and($target->content_override['text'])->toBe('Custom')
        ->and($target->content_override['media_ids'])->toBe([$newMedia->id]);
});

test('targets for deleted accounts are skipped', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);
    $post->targets()->firstOrFail()->account->forceDelete();

    $this->actingAs($this->user)->post(route('posts.duplicate', $post));

    $draft = Post::query()->where('status', PostStatus::Draft->value)->firstOrFail();

    expect($draft->targets()->count())->toBe(0);
});

test('a draft post is not eligible to be copied', function (): void {
    $post = Post::factory()->for($this->workspace)->create([
        'author_id' => $this->user->id,
        'status' => PostStatus::Draft->value,
    ]);

    $this->actingAs($this->user)
        ->post(route('posts.duplicate', $post))
        ->assertStatus(422);

    expect(Post::query()->where('status', PostStatus::Draft->value)->count())->toBe(1);
});

test('a post from another workspace cannot be duplicated', function (): void {
    $post = publishedPostWithMediaAndTarget($this->workspace, $this->user);
    [$other] = duplicateMember();

    $this->actingAs($other)
        ->post(route('posts.duplicate', $post))
        ->assertNotFound();
});
