<?php

use App\Enums\InstanceRole;
use App\Models\User;
use App\Support\InstanceSettings;
use Inertia\Testing\AssertableInertia;

test('instance owner can view instance settings', function () {
    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->get(route('instance-settings.edit'))
        ->assertOk();
});

test('regular users cannot view instance settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('instance-settings.edit'))
        ->assertForbidden();
});

test('instance owner can update instance settings', function () {
    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->put(route('instance-settings.update'), [
            'registrations_enabled' => false,
            'workspace_creation_enabled' => false,
            'usage_tracking_enabled' => false,
        ])
        ->assertRedirect();

    expect(app(InstanceSettings::class)->registrationsEnabled())->toBeFalse()
        ->and(app(InstanceSettings::class)->workspaceCreationEnabled())->toBeFalse();
});

test('workspace creation setting is disabled when workspaces are globally disabled', function () {
    config(['kit.workspaces.enabled' => false]);

    $owner = User::factory()->instanceOwner()->create();
    app(InstanceSettings::class)->update([
        'workspace_creation_enabled' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('instance-settings.edit'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspaces_enabled', false)
            ->where('settings.workspace_creation_enabled', false));
});

test('workspace creation setting cannot be enabled when workspaces are globally disabled', function () {
    config(['kit.workspaces.enabled' => false]);

    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->put(route('instance-settings.update'), [
            'registrations_enabled' => true,
            'workspace_creation_enabled' => true,
            'usage_tracking_enabled' => false,
        ])
        ->assertRedirect();

    expect(app(InstanceSettings::class)->workspaceCreationEnabled())->toBeFalse();
});

test('instance owner can view instance admins and search registered users by email', function () {
    $owner = User::factory()->instanceOwner()->create(['email' => 'owner@example.com']);
    $matchingUser = User::factory()->create(['email' => 'admin-candidate@example.com']);
    User::factory()->create(['email' => 'other@example.com']);

    $this->actingAs($owner)
        ->get(route('instance-settings.admins', ['search' => 'candidate']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/instance-admins')
            ->where('owners.0.email', 'owner@example.com')
            ->where('search', 'candidate')
            ->where('users.0.id', $matchingUser->id)
            ->where('users.0.email', 'admin-candidate@example.com')
            ->missing('users.1'));
});

test('regular users cannot view instance admins', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('instance-settings.admins'))
        ->assertForbidden();
});

test('instance owner can add another registered user as an instance owner', function () {
    $owner = User::factory()->instanceOwner()->create();
    $candidate = User::factory()->create(['email' => 'candidate@example.com']);

    $this->actingAs($owner)
        ->post(route('instance-settings.admins.store'), [
            'email' => 'candidate@example.com',
        ])
        ->assertRedirect();

    expect($candidate->fresh()->instance_role)->toBe(InstanceRole::Owner);
});

test('instance owner cannot add a missing user as an instance owner', function () {
    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->from(route('instance-settings.admins'))
        ->post(route('instance-settings.admins.store'), [
            'email' => 'missing@example.com',
        ])
        ->assertRedirect(route('instance-settings.admins'))
        ->assertSessionHasErrors('email');
});

test('instance owner can remove another instance owner', function () {
    $owner = User::factory()->instanceOwner()->create();
    $otherOwner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->delete(route('instance-settings.admins.destroy', $otherOwner))
        ->assertRedirect();

    expect($otherOwner->fresh()->instance_role)->toBeNull();
});

test('instance owner cannot remove the last instance owner', function () {
    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->from(route('instance-settings.admins'))
        ->delete(route('instance-settings.admins.destroy', $owner))
        ->assertRedirect(route('instance-settings.admins'))
        ->assertSessionHasErrors('owner');

    expect($owner->fresh()->instance_role)->toBe(InstanceRole::Owner);
});

test('instance owner cannot remove themselves while another owner exists', function () {
    $owner = User::factory()->instanceOwner()->create();
    User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->from(route('instance-settings.admins'))
        ->delete(route('instance-settings.admins.destroy', $owner))
        ->assertRedirect(route('instance-settings.admins'))
        ->assertSessionHasErrors('owner');

    expect($owner->fresh()->instance_role)->toBe(InstanceRole::Owner);
});
