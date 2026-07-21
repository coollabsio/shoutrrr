<?php

use App\Enums\InstanceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Support\InstanceSettings;

beforeEach(function (): void {
    $this->owner = User::factory()->create(['instance_role' => InstanceRole::Owner]);
});

it('paginates and searches the workspace usage table', function (): void {
    Workspace::factory()->create(['name' => 'Acme']);
    Workspace::factory()->create(['name' => 'Globex']);

    $this->actingAs($this->owner)
        ->get(route('instance-settings.usage', ['search' => 'Acme']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/instance-usage')
            ->has('workspace_usage.data', 1)
            ->where('workspace_usage.data.0.name', 'Acme')
            ->has('instance_summary.workspace_count'));
});

it('exposes the quota kind for each workspace', function (): void {
    $custom = Workspace::factory()->create(['name' => 'Custom', 'is_initial' => false]);
    app(InstanceSettings::class)->setXWorkspaceBudget($custom->id, 'unlimited');

    $this->actingAs($this->owner)
        ->get(route('instance-settings.usage', ['search' => 'Custom']))
        ->assertInertia(fn ($page) => $page->where('workspace_usage.data.0.quota.kind', 'unlimited'));
});

it('returns drilldown data only when a workspace is selected', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Zeta']);

    $this->actingAs($this->owner)
        ->get(route('instance-settings.usage'))
        ->assertInertia(fn ($page) => $page->missing('drilldown'));

    $this->actingAs($this->owner)
        ->get(route('instance-settings.usage', ['workspace' => $workspace->id]))
        ->assertInertia(fn ($page) => $page->has('drilldown.counters')->has('drilldown.error_events'));
});

it('includes the workspace quota in the drilldown payload', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Initech', 'is_initial' => false]);
    app(InstanceSettings::class)->setXWorkspaceBudget($workspace->id, 'unlimited');

    $this->actingAs($this->owner)
        ->get(route('instance-settings.usage', ['workspace' => $workspace->id]))
        ->assertInertia(fn ($page) => $page
            ->where('drilldown.workspace.id', $workspace->id)
            ->where('drilldown.workspace.quota.kind', 'unlimited'));
});

it('flags the initial workspace in the drilldown so its quota editor locks', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Prime', 'is_initial' => true]);

    $this->actingAs($this->owner)
        ->get(route('instance-settings.usage', ['workspace' => $workspace->id]))
        ->assertInertia(fn ($page) => $page
            ->where('drilldown.workspace.is_initial', true)
            ->where('drilldown.workspace.quota.kind', 'unlimited'));
});

it('includes the workspace owner in the drilldown payload', function (): void {
    $workspaceOwner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@example.test']);
    $workspace = Workspace::factory()->for($workspaceOwner, 'owner')->create(['name' => 'Umbrella']);

    $this->actingAs($this->owner)
        ->get(route('instance-settings.usage', ['workspace' => $workspace->id]))
        ->assertInertia(fn ($page) => $page
            ->where('drilldown.workspace.owner.name', 'Ada Owner')
            ->where('drilldown.workspace.owner.email', 'ada@example.test')
            ->has('drilldown.workspace.owner.avatar'));
});
