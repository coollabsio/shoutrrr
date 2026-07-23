<?php

use App\Enums\InstanceRole;
use App\Enums\Platform;
use App\Models\User;
use App\Support\InstanceSettings;

it('defaults every platform to available', function () {
    $settings = app(InstanceSettings::class);

    expect($settings->platformAvailable(Platform::X))->toBeTrue();
    expect($settings->platformsEnabled())->toBe([
        'x' => true,
        'bluesky' => true,
        'linkedin' => true,
        'facebook' => true,
        'instagram' => true,
        'threads' => true,
        'discord' => true,
    ]);
});

it('freezes a single platform while leaving the rest available', function () {
    $settings = app(InstanceSettings::class);
    $settings->update(['platforms_enabled' => ['x' => false]]);

    expect($settings->platformAvailable(Platform::X))->toBeFalse();
    expect($settings->platformAvailable(Platform::Bluesky))->toBeTrue();
});

it('stops polling for a frozen platform regardless of the polling toggle', function () {
    $settings = app(InstanceSettings::class);
    $settings->update([
        'engagement_polling_enabled' => ['x' => true],
        'platforms_enabled' => ['x' => false],
    ]);

    expect($settings->engagementPollingEnabled(Platform::X))->toBeFalse();
});

it('lets an owner view the platforms page', function () {
    $owner = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);

    $this->actingAs($owner)
        ->get(route('instance-settings.platforms'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/instance-platforms')
            ->has('platforms', 7)
            ->where('linkedin_community_management_enabled', false));
});

it('forbids a non-owner from the platforms page', function () {
    $user = User::factory()->create(['instance_role' => null]);

    $this->actingAs($user)
        ->get(route('instance-settings.platforms'))
        ->assertForbidden();
});

it('persists platform toggles for an owner', function () {
    $owner = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);

    $this->actingAs($owner)
        ->put(route('instance-settings.updatePlatforms'), [
            'platforms' => [
                'x' => false,
                'bluesky' => true,
                'linkedin' => true,
                'facebook' => true,
                'instagram' => true,
                'threads' => true,
                'discord' => true,
            ],
            'linkedin_community_management_enabled' => false,
        ])
        ->assertRedirect();

    expect(app(InstanceSettings::class)->platformAvailable(Platform::X))->toBeFalse();
});

it('persists the linkedin community management toggle from the platforms page', function () {
    $owner = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);

    expect(app(InstanceSettings::class)->linkedinCommunityManagementEnabled())->toBeFalse();

    $this->actingAs($owner)
        ->put(route('instance-settings.updatePlatforms'), [
            'platforms' => [
                'x' => true,
                'bluesky' => true,
                'linkedin' => true,
                'facebook' => true,
                'instagram' => true,
                'threads' => true,
                'discord' => true,
            ],
            'linkedin_community_management_enabled' => true,
        ])
        ->assertRedirect();

    expect(app(InstanceSettings::class)->linkedinCommunityManagementEnabled())->toBeTrue();
});

it('rejects a platforms update missing the linkedin community management field', function () {
    $owner = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);

    $this->actingAs($owner)
        ->put(route('instance-settings.updatePlatforms'), [
            'platforms' => [
                'x' => true,
                'bluesky' => true,
                'linkedin' => true,
                'facebook' => true,
                'instagram' => true,
                'threads' => true,
                'discord' => true,
            ],
        ])
        ->assertSessionHasErrors('linkedin_community_management_enabled');
});
