<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\InstanceSettings;

function ownerActingInFrozenTest(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    return [$user, $workspace];
}

beforeEach(function () {
    config()->set('services.x.client_id', 'id');
    config()->set('services.x.client_secret', 'secret');
});

it('reports a frozen platform as not enabled in capabilities', function () {
    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    $x = collect(Platform::capabilities())->firstWhere('platform', 'x');

    expect($x['enabled'])->toBeFalse();
    expect(collect(Platform::capabilities())->firstWhere('platform', 'bluesky')['enabled'])->toBeTrue();
});

it('404s the OAuth connect redirect for a frozen platform', function () {
    ownerActingInFrozenTest();
    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    test()->get(route('accounts.connect', ['platform' => 'x']))
        ->assertNotFound();
});

it('rejects Google Business Profile from the generic OAuth route', function () {
    ownerActingInFrozenTest();
    config()->set('services.google_business_profile.client_id', 'id');
    config()->set('services.google_business_profile.client_secret', 'secret');
    config()->set('services.google_business_profile.api_approved', true);

    test()->get(route('accounts.connect', ['platform' => 'google_business_profile']))
        ->assertNotFound();
});

it('404s the bespoke Google Business Profile redirect when frozen', function () {
    ownerActingInFrozenTest();
    config()->set('services.google_business_profile.client_id', 'id');
    config()->set('services.google_business_profile.client_secret', 'secret');
    config()->set('services.google_business_profile.api_approved', true);
    app(InstanceSettings::class)->update(['platforms_enabled' => ['google_business_profile' => false]]);

    test()->get(route('accounts.google-business-profile.redirect'))
        ->assertNotFound();
});
