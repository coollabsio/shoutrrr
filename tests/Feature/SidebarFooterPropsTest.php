<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\CommunityStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

function actingOwnerInWorkspace(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);
    test()->actingAs($user);

    return $user;
}

test('cloud shares a billing prop for billing managers and no community prop', function () {
    config(['subscriptions.enabled' => true]);
    actingOwnerInWorkspace();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('billing.subscribed', false)
        ->where('billing.manageUrl', route('billing.index'))
        ->where('community', null)
        ->where('updateAvailable', false)
    );
});

test('members without billing.manage do not receive a billing prop', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('billing', null)
    );
});

test('self-hosted shares a community prop and the update flag, no billing prop', function () {
    config(['subscriptions.enabled' => false]);
    config(['instance.community.repo' => 'coollabsio/shoutrrr']);
    config(['instance.community.sponsor_url' => 'https://github.com/sponsors/coollabsio']);
    Cache::put(CommunityStats::StarsCacheKey, 4210);
    Cache::put(CommunityStats::LatestVersionCacheKey, 'v99.0.0');
    actingOwnerInWorkspace();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->where('billing', null)
        ->where('community.repoUrl', 'https://github.com/coollabsio/shoutrrr')
        ->where('community.sponsorUrl', 'https://github.com/sponsors/coollabsio')
        ->where('community.stars', 4210)
        ->where('updateAvailable', true)
    );
});
