<?php

use App\Enums\Platform;
use App\Models\PostTarget;
use App\Services\Engagement\ReplyFetchCadence;
use App\Support\InstanceSettings;
use Carbon\CarbonImmutable;

function replyTarget(array $attrs = []): PostTarget
{
    return PostTarget::factory()->make(array_merge([
        'platform' => Platform::Bluesky,
        'posted_at' => CarbonImmutable::parse('2026-07-15 12:00:00'),
        'reply_fetched_at' => null,
        'reply_fetch_empty_streak' => 0,
    ], $attrs));
}

test('a never-fetched in-band post is due', function () {
    $now = CarbonImmutable::parse('2026-07-15 13:00:00'); // 1h old

    expect(app(ReplyFetchCadence::class)->isDue(replyTarget(), $now))->toBeTrue();
});

test('interval widens with post age band', function () {
    $cadence = app(ReplyFetchCadence::class);
    $posted = CarbonImmutable::parse('2026-07-15 00:00:00');

    // 1h old -> first band (30m); 48h old -> second band (120m)
    expect($cadence->intervalMinutes(replyTarget(['posted_at' => $posted]), $posted->addHours(1)))->toBe(30);
    expect($cadence->intervalMinutes(replyTarget(['posted_at' => $posted]), $posted->addHours(48)))->toBe(120);
});

test('an old post keeps polling at the steady tail interval (never stops)', function () {
    $posted = CarbonImmutable::parse('2026-07-15 00:00:00');
    $target = replyTarget(['posted_at' => $posted]);

    // 200h old -> past every band -> steady 1440m, and still due (never fetched)
    expect(app(ReplyFetchCadence::class)->intervalMinutes($target, $posted->addHours(200)))->toBe(1440);
    expect(app(ReplyFetchCadence::class)->isDue($target, $posted->addHours(200)))->toBeTrue();
});

test('the per-platform floor overrides a finer band (X never polls faster than its setting)', function () {
    $posted = CarbonImmutable::parse('2026-07-15 00:00:00');
    $target = replyTarget(['platform' => Platform::X, 'posted_at' => $posted]);

    // First band is 30m, but X's floor is 360m, so it wins.
    expect(app(ReplyFetchCadence::class)->intervalMinutes($target, $posted->addHours(1)))->toBe(360);
});

test('empty-streak multiplies the interval but is capped', function () {
    $posted = CarbonImmutable::parse('2026-07-15 00:00:00');
    $cadence = app(ReplyFetchCadence::class);

    // 3 empties in the first band: 30m * min(2^3, 8) = 30 * 8 = 240m
    $target = replyTarget(['posted_at' => $posted, 'reply_fetch_empty_streak' => 3]);
    expect($cadence->effectiveIntervalMinutes($target, $posted->addHours(1)))->toBe(240);
});

test('a post fetched within its effective interval is not due', function () {
    $posted = CarbonImmutable::parse('2026-07-15 00:00:00');
    $now = $posted->addHours(1);
    $target = replyTarget([
        'posted_at' => $posted,
        'reply_fetched_at' => $now->subMinutes(10), // < 30m band
    ]);

    expect(app(ReplyFetchCadence::class)->isDue($target, $now))->toBeFalse();
});

test('polling disabled for the platform yields a null interval and not due', function () {
    app(InstanceSettings::class)->update([
        'engagement_polling_enabled' => ['bluesky' => false],
    ]);

    $target = replyTarget(['posted_at' => CarbonImmutable::parse('2026-07-15 12:00:00')]);
    $now = CarbonImmutable::parse('2026-07-15 13:00:00');

    expect(app(ReplyFetchCadence::class)->intervalMinutes($target, $now))->toBeNull();
    expect(app(ReplyFetchCadence::class)->isDue($target, $now))->toBeFalse();
});
