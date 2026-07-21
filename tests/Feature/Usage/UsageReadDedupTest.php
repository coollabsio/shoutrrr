<?php

use App\Services\Usage\UsageReadDedup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    Cache::flush();
});

it('counts each id once per day per workspace/platform', function (): void {
    $dedup = app(UsageReadDedup::class);

    expect($dedup->countNew('ws-1', 'x', ['a', 'b', 'c']))->toBe(3)
        ->and($dedup->countNew('ws-1', 'x', ['b', 'c', 'd']))->toBe(1)
        ->and($dedup->countNew('ws-1', 'x', ['a', 'b', 'c', 'd']))->toBe(0);
});

it('dedupes duplicate ids within a single call', function (): void {
    expect(app(UsageReadDedup::class)->countNew('ws-1', 'x', ['a', 'a', 'a']))->toBe(1);
});

it('scopes dedup per workspace and per platform', function (): void {
    $dedup = app(UsageReadDedup::class);
    $dedup->countNew('ws-1', 'x', ['a']);

    expect($dedup->countNew('ws-2', 'x', ['a']))->toBe(1)
        ->and($dedup->countNew('ws-1', 'bluesky', ['a']))->toBe(1);
});

it('resets when the day rolls over', function (): void {
    $dedup = app(UsageReadDedup::class);
    Date::setTestNow('2026-07-18 10:00:00');
    expect($dedup->countNew('ws-1', 'x', ['a']))->toBe(0 + 1);

    Date::setTestNow('2026-07-19 10:00:00');
    expect($dedup->countNew('ws-1', 'x', ['a']))->toBe(1);
});
