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

test('discord accounts are never due for account metrics', function () {
    $discord = ConnectedAccount::factory()->make([
        'platform' => Platform::Discord,
        'metrics_captured_at' => null,
    ]);

    expect($this->cadence->accountDue($discord, $this->now))->toBeFalse();
});

test('post metrics age bands select an interval clamped to the platform floor', function () {
    app(InstanceSettings::class)->update([
        'post_metrics_poll_interval_minutes' => [
            'x' => 360,
            'bluesky' => 15,
            'linkedin' => 15,
        ],
    ]);

    $ageX = fn (int $hours) => PostTarget::factory()->make([
        'platform' => Platform::X,
        'posted_at' => $this->now->copy()->subHours($hours),
    ]);

    // Band 1 (< 6h, 60m) clamps up to the X floor (360m).
    expect($this->cadence->postIntervalSeconds($ageX(3), $this->now))->toBe(360 * 60);
    // Band 2 (< 24h, 180m) still clamps up to the X floor.
    expect($this->cadence->postIntervalSeconds($ageX(12), $this->now))->toBe(360 * 60);
    // Band 3 (< 72h, 720m) exceeds the floor — real savings start here.
    expect($this->cadence->postIntervalSeconds($ageX(48), $this->now))->toBe(720 * 60);
    // Band 4 (< 168h, 1440m).
    expect($this->cadence->postIntervalSeconds($ageX(100), $this->now))->toBe(1440 * 60);

    $ageBluesky = fn (int $hours) => PostTarget::factory()->make([
        'platform' => Platform::Bluesky,
        'posted_at' => $this->now->copy()->subHours($hours),
    ]);

    // Bluesky's floor (15m) never clamps — bands apply unmodified.
    expect($this->cadence->postIntervalSeconds($ageBluesky(3), $this->now))->toBe(60 * 60);
    expect($this->cadence->postIntervalSeconds($ageBluesky(48), $this->now))->toBe(720 * 60);
});

test('post metrics stop polling for good past the last band', function () {
    $veryOld = PostTarget::factory()->make([
        'platform' => Platform::X,
        'posted_at' => $this->now->copy()->subHours(200),
    ]);

    expect($this->cadence->postIntervalSeconds($veryOld, $this->now))->toBeNull();
    expect($this->cadence->postTargetDue($veryOld, $this->now))->toBeFalse();
});

test('effective interval widens by the consecutive unchanged streak, capped', function () {
    app(InstanceSettings::class)->update([
        'post_metrics_poll_interval_minutes' => ['x' => 360, 'bluesky' => 15, 'linkedin' => 15],
    ]);

    $base = fn (int $streak) => PostTarget::factory()->make([
        'platform' => Platform::X,
        'posted_at' => $this->now->copy()->subHours(48),
        'metrics_unchanged_streak' => $streak,
    ]);

    // Base band-3 interval is 720m; a streak of 0 leaves it unchanged.
    expect($this->cadence->effectiveIntervalSeconds($base(0), $this->now))->toBe(720 * 60);
    // Streak of 2 -> 2^2 = 4x.
    expect($this->cadence->effectiveIntervalSeconds($base(2), $this->now))->toBe(720 * 4 * 60);
    // Streak of 3 already hits the default cap of 8x...
    expect($this->cadence->effectiveIntervalSeconds($base(3), $this->now))->toBe(720 * 8 * 60);
    // ...and a much larger streak stays capped at the same 8x.
    expect($this->cadence->effectiveIntervalSeconds($base(10), $this->now))->toBe(720 * 8 * 60);
});

test('unchanged streak backoff postpones the next due capture without changing the hard stop', function () {
    app(InstanceSettings::class)->update([
        'post_metrics_poll_interval_minutes' => ['x' => 360, 'bluesky' => 15, 'linkedin' => 15],
    ]);

    // Band-3 base interval is 720m; captured 800m ago is due under the base
    // interval alone, but a streak-of-2 backoff (4x -> 2880m) postpones it.
    $target = PostTarget::factory()->make([
        'platform' => Platform::X,
        'posted_at' => $this->now->copy()->subHours(48),
        'metrics_captured_at' => $this->now->copy()->subMinutes(800),
        'metrics_unchanged_streak' => 2,
    ]);

    expect($this->cadence->postIntervalSeconds($target, $this->now))->toBeLessThan(800 * 60);
    expect($this->cadence->postTargetDue($target, $this->now))->toBeFalse();

    // Backoff can never resurrect a post past the hard stop.
    $stopped = PostTarget::factory()->make([
        'platform' => Platform::X,
        'posted_at' => $this->now->copy()->subHours(200),
        'metrics_captured_at' => $this->now->copy()->subYear(),
        'metrics_unchanged_streak' => 5,
    ]);
    expect($this->cadence->postTargetDue($stopped, $this->now))->toBeFalse();
});
