<?php

declare(strict_types=1);

use App\Enums\ReplyStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Database\Seeders\DummyEngagementSeeder;
use Illuminate\Support\Facades\Context;

beforeEach(function (): void {
    $this->workspace = Workspace::factory()->create(['slug' => 'test-workspace']);
    $this->user = User::factory()->create([
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->forceFill(['owner_id' => $this->user->id])->save();

    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);

    Context::add('workspace_id', $this->workspace->id);
});

test('dummy engagement seeder creates sixty plus inbox conversations', function (): void {
    $this->seed(DummyEngagementSeeder::class);

    $inbound = PostTargetReply::query()
        ->where('workspace_id', $this->workspace->id)
        ->where('is_ours', false)
        ->get();

    expect($inbound->count())->toBeGreaterThanOrEqual(DummyEngagementSeeder::CONVERSATION_COUNT);

    $conversations = $inbound
        ->groupBy(fn (PostTargetReply $reply): string => $reply->post_target_id.':'.$reply->conversation_remote_id);

    expect($conversations->count())->toBe(DummyEngagementSeeder::CONVERSATION_COUNT)
        ->and($inbound->where('status', ReplyStatus::Pending)->isNotEmpty())->toBeTrue()
        ->and($inbound->where('status', ReplyStatus::Responded)->isNotEmpty())->toBeTrue()
        ->and($inbound->where('status', ReplyStatus::Archived)->isNotEmpty())->toBeTrue()
        ->and($inbound->whereNull('read_at')->isNotEmpty())->toBeTrue()
        ->and(PostTargetReply::query()->where('workspace_id', $this->workspace->id)->where('is_ours', true)->exists())->toBeTrue()
        ->and(
            Post::query()
                ->where('workspace_id', $this->workspace->id)
                ->where('base_text', 'like', '%[dummy-engagement]%')
                ->count(),
        )->toBeGreaterThan(0);

    expect(ConnectedAccount::query()
        ->where('workspace_id', $this->workspace->id)
        ->whereNull('disabled_at')
        ->doesntExist())->toBeTrue();
});

test('dummy engagement seeder is idempotent for the marked posts', function (): void {
    $this->seed(DummyEngagementSeeder::class);
    $firstCount = PostTargetReply::query()->where('workspace_id', $this->workspace->id)->count();

    $this->seed(DummyEngagementSeeder::class);
    $secondCount = PostTargetReply::query()->where('workspace_id', $this->workspace->id)->count();

    expect($secondCount)->toBe($firstCount)
        ->and(
            Post::query()
                ->where('workspace_id', $this->workspace->id)
                ->where('base_text', 'like', '%[dummy-engagement]%')
                ->count(),
        )->toBe(5);
});
