<?php

use App\Enums\MetricsStatus;
use App\Models\PostTarget;
use App\Services\Metrics\MetricsCaptureCadence;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->cadence = app(MetricsCaptureCadence::class);
    $this->now = Date::now();
});

test('a fresh post is due hourly and an old post stops polling', function () {
    $fresh = PostTarget::factory()->make([
        'posted_at' => $this->now->copy()->subHours(3),
        'metrics_captured_at' => $this->now->copy()->subHours(2),
    ]);
    expect($this->cadence->postTargetDue($fresh, $this->now))->toBeTrue();

    $old = PostTarget::factory()->make([
        'posted_at' => $this->now->copy()->subDays(40),
        'metrics_captured_at' => null,
    ]);
    expect($this->cadence->postTargetDue($old, $this->now))->toBeFalse();
});

test('unsupported targets are never due', function () {
    $target = PostTarget::factory()->make([
        'posted_at' => $this->now->copy()->subHour(),
        'metrics_captured_at' => null,
        'metrics_status' => MetricsStatus::Unsupported,
    ]);
    expect($this->cadence->postTargetDue($target, $this->now))->toBeFalse();
});
