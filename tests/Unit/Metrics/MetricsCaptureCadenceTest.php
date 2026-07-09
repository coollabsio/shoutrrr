<?php

use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\MetricsCaptureCadence;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    $this->cadence = app(MetricsCaptureCadence::class);
    $this->now = Date::now();
});

test('a fresh post follows its platform interval and an old post stops polling', function () {
    app(InstanceSettings::class)->update([
        'post_metrics_poll_interval_minutes' => [
            'x' => 360,
            'bluesky' => 15,
            'linkedin' => 15,
        ],
    ]);

    $fresh = PostTarget::factory()->make([
        'platform' => Platform::X,
        'posted_at' => $this->now->copy()->subHours(3),
        'metrics_captured_at' => $this->now->copy()->subHours(2),
    ]);
    expect($this->cadence->postTargetDue($fresh, $this->now))->toBeFalse();

    $bluesky = PostTarget::factory()->make([
        'platform' => Platform::Bluesky,
        'posted_at' => $this->now->copy()->subHours(3),
        'metrics_captured_at' => $this->now->copy()->subHours(2),
    ]);
    expect($this->cadence->postTargetDue($bluesky, $this->now))->toBeTrue();

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

test('account metrics follow their platform interval', function () {
    app(InstanceSettings::class)->update([
        'account_metrics_poll_interval_minutes' => [
            'x' => 1440,
            'bluesky' => 120,
            'linkedin' => 120,
        ],
    ]);

    $x = ConnectedAccount::factory()->make([
        'platform' => Platform::X,
        'metrics_captured_at' => $this->now->copy()->subHours(3),
    ]);
    $bluesky = ConnectedAccount::factory()->make([
        'platform' => Platform::Bluesky,
        'metrics_captured_at' => $this->now->copy()->subHours(3),
    ]);

    expect($this->cadence->accountDue($x, $this->now))->toBeFalse();
    expect($this->cadence->accountDue($bluesky, $this->now))->toBeTrue();
});
