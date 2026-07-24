<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;

test('autoRepostConfig merges stored capabilities over config defaults', function (): void {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X,
        'capabilities' => ['auto_repost' => ['enabled' => true, 'min_percentile' => 0.8]],
    ]);

    $config = $account->autoRepostConfig();

    expect($config['enabled'])->toBeTrue()
        ->and($config['min_percentile'])->toBe(0.8)
        ->and($config['min_delay_hours'])->toBe(config('repost.defaults.min_delay_hours'))
        ->and($config['max_delay_hours'])->toBe(config('repost.defaults.max_delay_hours'))
        ->and($config['plateau_streak'])->toBe(config('repost.defaults.plateau_streak'));
});

test('autoRepostConfig defaults to disabled when capabilities are empty', function (): void {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'capabilities' => null]);

    expect($account->autoRepostConfig()['enabled'])->toBeFalse()
        ->and($account->autoRepostEnabled())->toBeFalse();
});

test('autoRepostEnabled is false on platforms without native repost even if flagged', function (): void {
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Instagram,
        'capabilities' => ['auto_repost' => ['enabled' => true]],
    ]);

    expect($account->autoRepostEnabled())->toBeFalse();
});
