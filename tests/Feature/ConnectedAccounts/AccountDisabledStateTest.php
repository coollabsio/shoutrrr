<?php

use App\Models\ConnectedAccount;

test('isDisabled reflects the disabled_at timestamp', function () {
    $enabled = ConnectedAccount::factory()->create();
    $disabled = ConnectedAccount::factory()->disabled()->create();

    expect($enabled->isDisabled())->toBeFalse()
        ->and($disabled->isDisabled())->toBeTrue();
});

test('the enabled scope excludes disabled accounts', function () {
    $enabled = ConnectedAccount::factory()->create();
    ConnectedAccount::factory()->disabled()->create();

    $ids = ConnectedAccount::withoutGlobalScopes()->enabled()->pluck('id');

    expect($ids->all())->toBe([$enabled->id]);
});
