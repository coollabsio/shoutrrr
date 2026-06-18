<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Support\MetricsPresenter;

test('forPost sums ok targets and flags support', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::Bluesky, 'status' => PostTargetStatus::Published,
        'metrics_status' => MetricsStatus::Ok, 'likes' => 10, 'comments' => 2, 'reposts' => 1,
    ]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::LinkedIn, 'status' => PostTargetStatus::Published,
        'metrics_status' => MetricsStatus::Unsupported, 'likes' => 0,
    ]);

    $payload = MetricsPresenter::forPost($post);

    expect($payload['supported'])->toBeTrue();
    expect($payload['totals'])->toBe(['likes' => 10, 'comments' => 2, 'reposts' => 1]);
    expect($payload['targets'])->toHaveCount(2);
});

test('all unsupported targets yields supported false and zero totals', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::LinkedIn, 'status' => PostTargetStatus::Published,
        'metrics_status' => MetricsStatus::Unsupported, 'likes' => 0, 'comments' => 0, 'reposts' => 0,
    ]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::LinkedIn, 'status' => PostTargetStatus::Published,
        'metrics_status' => MetricsStatus::Unsupported, 'likes' => 0, 'comments' => 0, 'reposts' => 0,
    ]);

    $payload = MetricsPresenter::forPost($post);

    expect($payload['supported'])->toBeFalse();
    expect($payload['totals'])->toBe(['likes' => 0, 'comments' => 0, 'reposts' => 0]);
    expect($payload['targets'])->toHaveCount(2);
});

test('non-published targets are excluded from targets list and totals', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::Bluesky, 'status' => PostTargetStatus::Published,
        'metrics_status' => MetricsStatus::Ok, 'likes' => 5, 'comments' => 1, 'reposts' => 2,
    ]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::Bluesky, 'status' => PostTargetStatus::Failed,
        'metrics_status' => MetricsStatus::Ok, 'likes' => 99, 'comments' => 99, 'reposts' => 99,
    ]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::X, 'status' => PostTargetStatus::Pending,
        'metrics_status' => null, 'likes' => 0, 'comments' => 0, 'reposts' => 0,
    ]);

    $payload = MetricsPresenter::forPost($post);

    expect($payload['targets'])->toHaveCount(1);
    expect($payload['totals'])->toBe(['likes' => 5, 'comments' => 1, 'reposts' => 2]);
});

test('captured_at is the max metrics_captured_at among ok targets', function () {
    $post = Post::factory()->create();
    $earlier = now()->subHour()->startOfSecond();
    $later = now()->startOfSecond();

    PostTarget::factory()->for($post)->create([
        'platform' => Platform::Bluesky, 'status' => PostTargetStatus::Published,
        'metrics_status' => MetricsStatus::Ok, 'likes' => 3,
        'metrics_captured_at' => $earlier,
    ]);
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::Bluesky, 'status' => PostTargetStatus::Published,
        'metrics_status' => MetricsStatus::Ok, 'likes' => 7,
        'metrics_captured_at' => $later,
    ]);

    $payload = MetricsPresenter::forPost($post);

    expect($payload['captured_at'])->toBe($later->toIso8601String());
});

test('captured_at is null when no ok target has been sampled', function () {
    $post = Post::factory()->create();
    PostTarget::factory()->for($post)->create([
        'platform' => Platform::Bluesky, 'status' => PostTargetStatus::Published,
        'metrics_status' => MetricsStatus::Ok, 'likes' => 0,
        'metrics_captured_at' => null,
    ]);

    $payload = MetricsPresenter::forPost($post);

    expect($payload['captured_at'])->toBeNull();
});

test('forPost returns each ok target an ordered engagement series', function () {
    $post = Post::factory()->create();
    $target = PostTarget::factory()->for($post)->create([
        'platform' => Platform::Bluesky,
        'status' => PostTargetStatus::Published,
        'metrics_status' => MetricsStatus::Ok,
        'likes' => 10,
    ]);
    PostTargetMetric::factory()->for($target, 'target')->create([
        'captured_at' => now()->subHours(2), 'likes' => 4,
    ]);
    PostTargetMetric::factory()->for($target, 'target')->create([
        'captured_at' => now()->subHour(), 'likes' => 9,
    ]);

    $row = MetricsPresenter::forPost($post)['targets'][0];

    expect($row['series'])->toHaveCount(2);
    expect($row['series'][0]['likes'])->toBe(4);   // oldest first
    expect($row['series'][1]['likes'])->toBe(9);
});
