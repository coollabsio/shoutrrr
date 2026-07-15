<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AppVersion;
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

test('cloud defers a billing prop for billing managers and no community prop', function () {
    config(['subscriptions.enabled' => true]);
    actingOwnerInWorkspace();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->missing('billing')
        ->missing('community')
        ->missing('updateAvailable')
        ->loadDeferredProps('sidebar', fn ($reload) => $reload
            ->where('billing.subscribed', false)
            ->where('billing.manageUrl', route('billing.index'))
            ->where('community', null)
            ->where('updateAvailable', false)
        )
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
        ->missing('billing')
        ->loadDeferredProps('sidebar', fn ($reload) => $reload
            ->where('billing', null)
        )
    );
});

test('self-hosted defers a community prop and the update flag, no billing prop', function () {
    config(['subscriptions.enabled' => false]);
    config(['instance.community.repo' => 'coollabsio/shoutrrr']);
    config(['instance.community.sponsor_url' => 'https://github.com/sponsors/coollabsio']);
    Cache::put(CommunityStats::StarsCacheKey, 4210);
    Cache::put(CommunityStats::LatestOverallCacheKey, 'v99.0.0');
    actingOwnerInWorkspace();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->missing('community')
        ->missing('updateAvailable')
        ->loadDeferredProps('sidebar', fn ($reload) => $reload
            ->where('billing', null)
            ->where('community.repoUrl', 'https://github.com/coollabsio/shoutrrr')
            ->where('community.sponsorUrl', 'https://github.com/sponsors/coollabsio')
            ->where('community.stars', 4210)
            ->where('updateAvailable', true)
        )
    );
});

test('self-hosted names the available version and links to its release', function () {
    config(['subscriptions.enabled' => false]);
    config(['instance.community.repo' => 'coollabsio/shoutrrr']);
    Cache::put(CommunityStats::LatestOverallCacheKey, 'v99.0.0');
    actingOwnerInWorkspace();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->missing('latestVersion')
        ->loadDeferredProps('sidebar', fn ($reload) => $reload
            ->where('updateAvailable', true)
            ->where('latestVersion', 'v99.0.0')
            ->where('latestReleaseUrl', 'https://github.com/coollabsio/shoutrrr/releases/tag/v99.0.0')
        )
    );
});

test('self-hosted up-to-date exposes no available version', function () {
    config(['subscriptions.enabled' => false]);
    Cache::put(CommunityStats::LatestOverallCacheKey, AppVersion::current());
    actingOwnerInWorkspace();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->missing('updateAvailable')
        ->loadDeferredProps('sidebar', fn ($reload) => $reload
            ->where('updateAvailable', false)
            ->where('latestVersion', null)
            ->where('latestReleaseUrl', null)
        )
    );
});

test('cloud never exposes an available version', function () {
    config(['subscriptions.enabled' => true]);
    Cache::put(CommunityStats::LatestOverallCacheKey, 'v99.0.0');
    actingOwnerInWorkspace();

    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page
        ->missing('updateAvailable')
        ->loadDeferredProps('sidebar', fn ($reload) => $reload
            ->where('updateAvailable', false)
            ->where('latestVersion', null)
            ->where('latestReleaseUrl', null)
        )
    );
});
