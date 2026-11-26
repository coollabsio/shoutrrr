<?php

use App\Dto\Post\TikTokPostOptions;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\TikTokPostMode;
use App\Enums\WorkspaceRole;
use App\Jobs\DeletePostTarget;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\DeletePostTool;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\PostListItem;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;

/**
 * A published post with one X target and one TikTok target, both live.
 *
 * @return array{0: Post, 1: PostTarget, 2: PostTarget}
 */
function publishedToXAndTikTok(Workspace $workspace, ?User $author = null): array
{
    $post = Post::factory()->for($workspace)->create([
        ...($author instanceof User ? ['author_id' => $author->id] : []),
        'status' => PostStatus::Published->value,
    ]);
    $x = PostTarget::factory()->for($post)->create([
        'platform' => Platform::X->value,
        'status' => PostTargetStatus::Published->value,
        'remote_id' => 'x-remote',
    ]);
    $tiktok = PostTarget::factory()->for($post)->create([
        'platform' => Platform::TikTok->value,
        'status' => PostTargetStatus::Published->value,
        'remote_id' => 'v_pub_1',
    ]);

    return [$post, $x, $tiktok];
}

test('deleting in the app removes the tiktok copy locally and says it stays live', function () {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);
    [$post, $x, $tiktok] = publishedToXAndTikTok($workspace, $user);

    $this->actingAs($user)->delete(route('posts.destroy', $post))
        ->assertRedirect(route('posts.index'))
        ->assertSessionHas('success', "Post deleted from connected accounts where possible. It is still live on TikTok, which can't delete posts through its API — delete it there directly.");

    expect($post->fresh()->status)->toBe(PostStatus::Deleted)
        ->and($tiktok->fresh()->status)->toBe(PostTargetStatus::Deleted)
        ->and($x->fresh()->status)->toBe(PostTargetStatus::Deleting);
    Queue::assertPushed(DeletePostTarget::class, 1);
    Queue::assertPushed(DeletePostTarget::class, fn (DeletePostTarget $job): bool => $job->target->is($x));
});

test('deleting through the api never queues a tiktok remote delete', function () {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    [$post, $x, $tiktok] = publishedToXAndTikTok($workspace, $user);

    $this->withToken($token)->deleteJson("/api/v1/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true)
        ->assertJsonPath('remote', true)
        ->assertJsonPath('message', "Remote deletion queued for published targets. It is still live on TikTok, which can't delete posts through its API — delete it there directly.");

    expect($tiktok->fresh()->status)->toBe(PostTargetStatus::Deleted);
    Queue::assertPushed(DeletePostTarget::class, 1);
    Queue::assertPushed(DeletePostTarget::class, fn (DeletePostTarget $job): bool => $job->target->is($x));
});

test('deleting through mcp never queues a tiktok remote delete', function () {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);
    [$post, $x, $tiktok] = publishedToXAndTikTok($workspace);

    ShoutrrrServer::actingAs($user)->tool(DeletePostTool::class, ['post_id' => $post->id, 'confirm' => true])
        ->assertOk()
        ->assertSee('It is still live on TikTok');

    expect($tiktok->fresh()->status)->toBe(PostTargetStatus::Deleted);
    Queue::assertPushed(DeletePostTarget::class, fn (DeletePostTarget $job): bool => $job->target->is($x));
    Queue::assertNotPushed(DeletePostTarget::class, fn (DeletePostTarget $job): bool => $job->target->is($tiktok));
});

test('the delete message stays unchanged when nothing is left live', function () {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => PostStatus::Published->value]);
    PostTarget::factory()->for($post)->create(['platform' => Platform::X->value, 'remote_id' => 'x-remote']);

    $this->withToken($token)->deleteJson("/api/v1/posts/{$post->id}")
        ->assertJsonPath('message', 'Remote deletion queued for published targets.');
});

test('deleting through mcp does not claim a remote delete when only tiktok was published', function () {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create(['status' => PostStatus::Published->value]);
    PostTarget::factory()->for($post)->create(['platform' => Platform::TikTok->value, 'remote_id' => '7710962537105247510']);

    ShoutrrrServer::actingAs($user)->tool(DeletePostTool::class, ['post_id' => $post->id, 'confirm' => true])
        ->assertOk()
        ->assertSee('"remote":false', false)
        ->assertSee('Post deleted. It is still live on TikTok')
        ->assertDontSee('Remote deletion queued');

    Queue::assertNothingPushed();
});

test('deleting a post sent to the tiktok inbox says the draft stays there, not that it is live', function () {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => PostStatus::Published->value]);
    $inbox = PostTarget::factory()->for($post)->create([
        'platform' => Platform::TikTok->value,
        'status' => PostTargetStatus::Published->value,
        'remote_id' => 'v_inbox_1',
        'tiktok_options' => new TikTokPostOptions(mode: TikTokPostMode::Inbox),
    ]);

    $this->withToken($token)->deleteJson("/api/v1/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('remote', false)
        ->assertJsonPath('message', 'Post deleted. The draft sent to the TikTok inbox is not removed: delete it in the TikTok app, and the post too if it was published from there.');

    expect($inbox->fresh()->status)->toBe(PostTargetStatus::Deleted);
    Queue::assertNothingPushed();
});

test('the delete notice names both a live tiktok post and an inbox draft', function () {
    $post = Post::factory()->create(['status' => PostStatus::Published->value]);
    PostTarget::factory()->for($post)->create(['platform' => Platform::TikTok->value, 'remote_id' => 'v_pub_1']);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::TikTok->value,
        'remote_id' => 'v_inbox_1',
        'tiktok_options' => new TikTokPostOptions(mode: TikTokPostMode::Inbox),
    ]);

    expect($post->load('targets')->stillLiveNotice())->toBe(
        "It is still live on TikTok, which can't delete posts through its API — delete it there directly. "
        .'The draft sent to the TikTok inbox is not removed: delete it in the TikTok app, and the post too if it was published from there.',
    );
});

test('the posts list tells the delete dialog which tiktok targets went to the inbox', function () {
    $post = Post::factory()->create(['status' => PostStatus::Published->value]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::TikTok->value,
        'tiktok_options' => new TikTokPostOptions(mode: TikTokPostMode::Inbox),
    ]);

    $item = PostListItem::make($post->load(['author', 'targets', 'media']));

    expect($item['targets'][0]['tiktok']['post_mode'] ?? null)->toBe('inbox');
});
