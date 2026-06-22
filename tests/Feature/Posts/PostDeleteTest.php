<?php

declare(strict_types=1);

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\DeletePostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;

/**
 * Create a workspace member whose current workspace + request Context are set,
 * so post route-model binding resolves and the PostPolicy passes.
 *
 * @return array{0: User, 1: Workspace}
 */
function deleteTestMember(): array
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

it('hard-deletes a draft and dispatches no remote-delete job', function (): void {
    Queue::fake();
    [$user, $workspace] = deleteTestMember();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id, 'status' => PostStatus::Draft->value,
    ]);

    $this->actingAs($user)->delete(route('posts.destroy', $post))->assertRedirect();

    expect(Post::query()->whereKey($post->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('hard-deletes a scheduled post and dispatches no remote-delete job', function (): void {
    Queue::fake();
    [$user, $workspace] = deleteTestMember();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id, 'status' => PostStatus::Scheduled->value,
    ]);

    $this->actingAs($user)->delete(route('posts.destroy', $post))->assertRedirect();

    expect(Post::query()->whereKey($post->id)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('redirects to the posts index after deleting the post currently being viewed', function (): void {
    Queue::fake();
    [$user, $workspace] = deleteTestMember();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id, 'status' => PostStatus::Draft->value,
    ]);

    $this->actingAs($user)
        ->from(route('posts.show', $post))
        ->delete(route('posts.destroy', $post))
        ->assertRedirect(route('posts.index'));

    expect(Post::query()->whereKey($post->id)->exists())->toBeFalse();
});

it('soft-deletes a published post and dispatches remote delete per target with a remote id', function (): void {
    Queue::fake();
    [$user, $workspace] = deleteTestMember();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id, 'status' => PostStatus::Published->value,
    ]);
    $published = PostTarget::factory()->for($post)->create([
        'status' => PostTargetStatus::Published->value, 'remote_id' => 'remote-1',
    ]);
    PostTarget::factory()->for($post)->create([
        'status' => PostTargetStatus::Pending->value, 'remote_id' => null,
    ]);

    $this->actingAs($user)->delete(route('posts.destroy', $post))->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Deleted)
        ->and($post->deleted_at)->not->toBeNull();
    Queue::assertPushed(DeletePostTarget::class, 1);
    Queue::assertPushed(DeletePostTarget::class,
        fn (DeletePostTarget $job): bool => $job->target->is($published));
});

it('soft-deletes partial and failed posts via the remote-delete path', function (PostStatus $status): void {
    Queue::fake();
    [$user, $workspace] = deleteTestMember();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id, 'status' => $status->value,
    ]);
    PostTarget::factory()->for($post)->create([
        'status' => PostTargetStatus::Published->value, 'remote_id' => 'remote-x',
    ]);

    $this->actingAs($user)->delete(route('posts.destroy', $post))->assertRedirect();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Deleted)
        ->and($post->deleted_at)->not->toBeNull();
    Queue::assertPushed(DeletePostTarget::class, 1);
})->with([
    'partial' => [PostStatus::Partial],
    'failed' => [PostStatus::Failed],
]);

it('soft-deletes a published post with no remote ids and dispatches nothing', function (): void {
    Queue::fake();
    [$user, $workspace] = deleteTestMember();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id, 'status' => PostStatus::Published->value,
    ]);
    PostTarget::factory()->for($post)->create([
        'status' => PostTargetStatus::Failed->value, 'remote_id' => null,
    ]);

    $this->actingAs($user)->delete(route('posts.destroy', $post))->assertRedirect();

    expect($post->refresh()->status)->toBe(PostStatus::Deleted);
    Queue::assertNothingPushed();
});

it('forbids deleting a post for a non-member of the workspace', function (): void {
    Queue::fake();
    [$owner, $workspace] = deleteTestMember();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $owner->id, 'status' => PostStatus::Published->value,
    ]);
    PostTarget::factory()->for($post)->create([
        'status' => PostTargetStatus::Published->value, 'remote_id' => 'remote-1',
    ]);

    // An authenticated user pointed at the workspace but with no membership.
    $intruder = User::factory()->create(['current_workspace_id' => $workspace->id]);

    $this->actingAs($intruder)->delete(route('posts.destroy', $post))->assertForbidden();

    expect($post->refresh()->status)->toBe(PostStatus::Published);
    Queue::assertNothingPushed();
});
