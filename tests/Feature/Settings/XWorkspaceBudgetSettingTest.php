<?php

use App\Support\InstanceSettings;

it('stores, reads, and clears a per-workspace X budget override', function (): void {
    $settings = app(InstanceSettings::class);

    expect($settings->xWorkspaceBudget('ws-1'))->toBeNull();

    $settings->setXWorkspaceBudget('ws-1', 1500);
    expect($settings->xWorkspaceBudget('ws-1'))->toBe(1500);

    $settings->setXWorkspaceBudget('ws-1', 'unlimited');
    expect($settings->xWorkspaceBudget('ws-1'))->toBe('unlimited');

    $settings->setXWorkspaceBudget('ws-1', null);
    expect($settings->xWorkspaceBudget('ws-1'))->toBeNull()
        ->and($settings->xWorkspaceBudgets())->toBe([]);
});
