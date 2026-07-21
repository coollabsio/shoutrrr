<?php

use App\Enums\InstanceRole;
use App\Models\User;
use App\Support\InstanceSettings;

it('is disabled by default', function () {
    expect(app(InstanceSettings::class)->quoteTweetsEnabled())->toBeFalse();
});

it('lets an instance owner enable quote tweets', function () {
    $owner = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);

    $this->actingAs($owner)->put('/settings/instance', [
        'registrations_enabled' => false,
        'workspace_creation_enabled' => true,
        'usage_tracking_enabled' => false,
        'quote_tweets_enabled' => true,
        'linkedin_community_management_enabled' => false,
    ])->assertRedirect();

    expect(app(InstanceSettings::class)->quoteTweetsEnabled())->toBeTrue();
});

it('rejects a missing quote_tweets_enabled field', function () {
    $owner = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);

    $this->actingAs($owner)->put('/settings/instance', [
        'registrations_enabled' => false,
        'workspace_creation_enabled' => true,
        'usage_tracking_enabled' => false,
    ])->assertSessionHasErrors('quote_tweets_enabled');
});
