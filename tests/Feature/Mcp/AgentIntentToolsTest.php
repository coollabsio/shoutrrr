<?php

use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Jobs\PublishPostTarget;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\PublishNowTool;
use App\Mcp\Tools\ScheduleAtTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
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
