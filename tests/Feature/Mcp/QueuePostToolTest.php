<?php

use App\Enums\PostStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\QueuePostTool;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

test('queue_post returns a clear message when no slot is configured', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create();

    $response = ShoutrrrServer::actingAs($user)->tool(QueuePostTool::class, ['post_id' => $post->id]);

    // No posting-schedule slots → resolver returns null → tool errors with guidance.
    $response->assertHasErrors();
});

test('queue_post schedules into the next open slot and returns it', function (): void {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00'); // Monday
    [$user, $workspace] = mcpOwner();
    queueToolSchedule($workspace, [[1, 9], [1, 11]]);
    $post = Post::factory()->for($workspace)->create(['status' => PostStatus::Draft]);

    ShoutrrrServer::actingAs($user)->tool(QueuePostTool::class, ['post_id' => $post->id])
        ->assertOk()
        ->assertSee('"scheduled_at": "2026-05-18T09:00:00+00:00"');

    expect($post->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($post->fresh()->scheduled_at->toIso8601String())->toBe('2026-05-18T09:00:00+00:00');
});

test('queue_post takes a chosen open slot and rejects a time that is not one', function (): void {
    CarbonImmutable::setTestNow('2026-05-18T08:30:00+00:00');
    [$user, $workspace] = mcpOwner();
    queueToolSchedule($workspace, [[1, 9], [1, 11]]);
    $post = Post::factory()->for($workspace)->create(['status' => PostStatus::Draft]);

    ShoutrrrServer::actingAs($user)->tool(QueuePostTool::class, ['post_id' => $post->id, 'scheduled_at' => '2026-05-18T10:00:00+00:00'])
        ->assertHasErrors(['That time is not an open slot.']);
    expect($post->fresh()->status)->toBe(PostStatus::Draft);

    ShoutrrrServer::actingAs($user)->tool(QueuePostTool::class, ['post_id' => $post->id, 'scheduled_at' => '2026-05-18T11:00:00+00:00'])
        ->assertOk();
    expect($post->fresh()->scheduled_at->toIso8601String())->toBe('2026-05-18T11:00:00+00:00');
});

/**
 * @param  list<array{0: int, 1: int}>  $slots  [weekday, hour] pairs
 */
function queueToolSchedule(Workspace $workspace, array $slots): void
{
    $schedule = PostingSchedule::factory()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);

    foreach ($slots as $i => [$weekday, $hour]) {
        PostingScheduleSlot::factory()->create([
            'posting_schedule_id' => $schedule->id,
            'weekday' => $weekday,
            'hour' => $hour,
            'minute' => 0,
            'position' => $i,
        ]);
    }
}
