<?php

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Repost\RepostEligibility;
use Illuminate\Support\Facades\Date;

function repostConfig(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'min_delay_hours' => 24,
        'max_delay_hours' => 168,
        'plateau_streak' => 2,
        'min_percentile' => 0.5,
    ], $overrides);
}

beforeEach(function (): void {
    $this->eligibility = app(RepostEligibility::class);
    $this->now = Date::now()->toImmutable();
});

test('timing not due before the min-delay floor', function (): void {
    $target = PostTarget::factory()->make([
        'posted_at' => $this->now->subHours(10),
        'metrics_unchanged_streak' => 5,
    ]);

    expect($this->eligibility->timingDue($target, $this->now, repostConfig()))->toBeFalse();
});

test('timing due once engagement plateaus past the floor', function (): void {
    $target = PostTarget::factory()->make([
        'posted_at' => $this->now->subHours(30),
        'metrics_unchanged_streak' => 2,
    ]);

    expect($this->eligibility->timingDue($target, $this->now, repostConfig()))->toBeTrue();
});

test('timing due at the max-delay ceiling even without a plateau', function (): void {
    $target = PostTarget::factory()->make([
        'posted_at' => $this->now->subHours(200),
        'metrics_unchanged_streak' => 0,
    ]);

    expect($this->eligibility->timingDue($target, $this->now, repostConfig()))->toBeTrue();
});

test('timing not due while still climbing between floor and ceiling', function (): void {
    $target = PostTarget::factory()->make([
        'posted_at' => $this->now->subHours(40),
        'metrics_unchanged_streak' => 0,
    ]);

    expect($this->eligibility->timingDue($target, $this->now, repostConfig()))->toBeFalse();
});

test('gate cold-starts to true on any engagement below the sample floor', function (): void {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'posted_at' => $this->now->subHours(30),
        'likes' => 1,
    ]);

    expect($this->eligibility->passesGate($target, $this->now, repostConfig()))->toBeTrue();
});

test('gate passes when the target is above the account median', function (): void {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);

    // 6 mature baseline posts with low engagement.
    PostTarget::factory()->count(6)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'posted_at' => $this->now->subDays(3),
        'likes' => 1, 'comments' => 0, 'reposts' => 0,
    ]);

    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'posted_at' => $this->now->subHours(30),
        'likes' => 100, 'comments' => 10, 'reposts' => 5,
    ]);

    expect($this->eligibility->passesGate($target, $this->now, repostConfig()))->toBeTrue();
});

test('gate fails when the target is below the account median', function (): void {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);

    PostTarget::factory()->count(6)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'posted_at' => $this->now->subDays(3),
        'likes' => 100, 'comments' => 0, 'reposts' => 0,
    ]);

    $target = PostTarget::factory()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'posted_at' => $this->now->subHours(30),
        'likes' => 1, 'comments' => 0, 'reposts' => 0,
    ]);

    expect($this->eligibility->passesGate($target, $this->now, repostConfig()))->toBeFalse();
});

test('shouldRepost is false when the account has auto-repost disabled', function (): void {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['auto_repost' => ['enabled' => false]],
    ]);
    $post = Post::factory()->create();
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'posted_at' => $this->now->subHours(200),
        'metrics_unchanged_streak' => 5,
        'likes' => 100,
    ]);

    expect($this->eligibility->shouldRepost($target, $this->now))->toBeFalse();
});

test('per-post override false blocks the boost; true bypasses the gate', function (): void {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['auto_repost' => ['enabled' => true]],
    ]);

    // A dud post (would fail the gate) that is force-boosted, past the ceiling.
    $forced = Post::factory()->create(['auto_repost' => true]);
    $forcedTarget = PostTarget::factory()->create([
        'post_id' => $forced->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'posted_at' => $this->now->subHours(200),
        'likes' => 0,
    ]);

    $off = Post::factory()->create(['auto_repost' => false]);
    $offTarget = PostTarget::factory()->create([
        'post_id' => $off->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'posted_at' => $this->now->subHours(200),
        'metrics_unchanged_streak' => 5,
        'likes' => 100,
    ]);

    expect($this->eligibility->shouldRepost($forcedTarget, $this->now))->toBeTrue()
        ->and($this->eligibility->shouldRepost($offTarget, $this->now))->toBeFalse();
});

test('shouldRepost is false once already reposted', function (): void {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['auto_repost' => ['enabled' => true]],
    ]);
    $post = Post::factory()->create(['auto_repost' => true]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'platform' => Platform::X,
        'status' => PostTargetStatus::Published,
        'posted_at' => $this->now->subHours(200),
        'reposted_at' => $this->now->subHour(),
    ]);

    expect($this->eligibility->shouldRepost($target, $this->now))->toBeFalse();
});
