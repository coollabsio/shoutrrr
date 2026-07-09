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
