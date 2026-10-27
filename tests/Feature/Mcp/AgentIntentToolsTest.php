<?php

use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Jobs\PublishPostTarget;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\PublishNowTool;
use App\Mcp\Tools\ScheduleAtTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

test('publish_now requires confirmation and does not create a post', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    bindTokenToWorkspace($user, $workspace);

    $response = ShoutrrrServer::actingAs($user)->tool(PublishNowTool::class, [
        'text' => 'Not yet',
        'idempotency_key' => 'mcp-1',
        'destination' => ['kind' => 'all'],
    ]);

    $response->assertHasErrors();
    expect(Post::query()->count())->toBe(0);
});

test('publish_now with confirm publishes once and replays', function () {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $arguments = [
        'text' => 'Ship it',
        'idempotency_key' => 'mcp-2',
        'confirm' => true,
        'destination' => ['kind' => 'all'],
    ];

    $response = ShoutrrrServer::actingAs($user)->tool(PublishNowTool::class, $arguments);

    $response->assertOk()->assertSee('Ship it');
    $post = Post::query()->where('workspace_id', $workspace->id)->sole();
    expect($post->origin)->toBe(PostOrigin::Agent)
        ->and($post->status)->toBe(PostStatus::Publishing);
    Queue::assertPushed(PublishPostTarget::class, 1);

    $replay = ShoutrrrServer::actingAs($user)->tool(PublishNowTool::class, $arguments);

    $replay->assertOk()->assertSee('replayed');
    expect(Post::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('schedule_at creates a scheduled post', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $response = ShoutrrrServer::actingAs($user)->tool(ScheduleAtTool::class, [
        'text' => 'Later',
        'idempotency_key' => 'mcp-3',
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'destination' => ['kind' => 'all'],
    ]);

    $response->assertOk()->assertSee('Later');
    expect(Post::query()->where('workspace_id', $workspace->id)->sole()->status)->toBe(PostStatus::Scheduled);
});

test('publish_now publishes a thread with media and mentions', function () {
    Queue::fake();
    [$user, $workspace] = mcpOwner();
    ConnectedAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id]);
    $media = PostMedia::factory()->for($workspace)->create(['post_id' => null]);

    $response = ShoutrrrServer::actingAs($user)->tool(PublishNowTool::class, [
        'segments' => ['Hi {{mention:m1}}', 'part two'],
        'media_ids' => [$media->id],
        'mentions' => [['id' => 'm1', 'label' => 'Ada', 'handles' => ['bluesky' => '@ada.bsky.social']]],
        'idempotency_key' => 'mcp-thread',
        'confirm' => true,
        'destination' => ['kind' => 'all'],
    ]);

    $response->assertOk()->assertSee('queued');
    $post = Post::query()->where('workspace_id', $workspace->id)->sole();
    expect($post->segments)->toBe(['Hi {{mention:m1}}', 'part two'])
        ->and($post->mentions)->toBe([['id' => 'm1', 'label' => 'Ada', 'handles' => ['bluesky' => '@ada.bsky.social']]])
        ->and($media->fresh()->post_id)->toBe($post->id)
        ->and($post->targets()->sole()->sections)->toBe(['Hi @ada.bsky.social', 'part two']);
});

test('publish_now rejects a replayed key whose media differ', function () {
    Queue::fake();
    [$user, $workspace] = mcpOwner();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $first = PostMedia::factory()->for($workspace)->create(['post_id' => null]);
    $second = PostMedia::factory()->for($workspace)->create(['post_id' => null]);

    $arguments = [
        'text' => 'Same text',
        'media_ids' => [$first->id],
        'idempotency_key' => 'mcp-media',
        'confirm' => true,
        'destination' => ['kind' => 'all'],
    ];

    ShoutrrrServer::actingAs($user)->tool(PublishNowTool::class, $arguments)->assertOk();

    ShoutrrrServer::actingAs($user)->tool(PublishNowTool::class, [...$arguments, 'media_ids' => [$second->id]])
        ->assertHasErrors(['This idempotency key was already used for a different request.']);

    expect(Post::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('publish_now rejects media from another workspace before creating a post', function () {
    Queue::fake();
    [$user, $workspace] = mcpOwner();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $foreign = PostMedia::factory()->for(Workspace::factory())->create(['post_id' => null]);

    ShoutrrrServer::actingAs($user)->tool(PublishNowTool::class, [
        'text' => 'With stolen media',
        'media_ids' => [$foreign->id],
        'idempotency_key' => 'mcp-foreign',
        'confirm' => true,
        'destination' => ['kind' => 'all'],
    ])->assertHasErrors(['No media with these ids exists in this workspace.']);

    expect(Post::query()->count())->toBe(0)
        ->and($foreign->fresh()->post_id)->toBeNull();
});

test('schedule_at schedules a thread', function () {
    [$user, $workspace] = mcpOwner();

    ShoutrrrServer::actingAs($user)->tool(ScheduleAtTool::class, [
        'segments' => ['later one', 'later two'],
        'idempotency_key' => 'mcp-sched-thread',
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'destination' => ['kind' => 'all'],
    ])->assertOk();

    $post = Post::query()->where('workspace_id', $workspace->id)->sole();
    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->segments)->toBe(['later one', 'later two']);
});
