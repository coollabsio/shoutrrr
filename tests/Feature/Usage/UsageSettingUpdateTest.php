<?php

use App\Enums\InstanceRole;
use App\Models\User;
use App\Support\InstanceSettings;

it('lets an instance owner enable usage tracking', function () {
    $owner = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);

    $this->actingAs($owner)->put('/settings/instance', [
        'registrations_enabled' => false,
        'workspace_creation_enabled' => true,
        'usage_tracking_enabled' => true,
    ])->assertRedirect();

    expect(app(InstanceSettings::class)->usageTrackingEnabled())->toBeTrue();
});

it('rejects a missing usage_tracking_enabled field', function () {
    $owner = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);

    $this->actingAs($owner)->put('/settings/instance', [
        'registrations_enabled' => false,
        'workspace_creation_enabled' => true,
    ])->assertSessionHasErrors('usage_tracking_enabled');
});
