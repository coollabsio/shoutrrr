<?php

use App\Enums\InstanceRole;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Workspace;

function usageReportOwner(): User
{
    return User::factory()->create(['instance_role' => InstanceRole::Owner->value]);
}

it('returns 404 when usage tracking is disabled', function () {
    $this->actingAs(usageReportOwner())->getJson('/settings/instance/usage')->assertNotFound();
});

it('returns 403 for a non-owner when enabled', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);

    $this->actingAs(User::factory()->create())->getJson('/settings/instance/usage')->assertForbidden();
});

it('aggregates events by category for an owner', function () {
    config()->set('instance.defaults.usage_tracking_enabled', true);
    $workspace = Workspace::factory()->create();
    UsageEvent::factory()->count(3)->create(['workspace_id' => $workspace->id, 'category' => 'publish', 'quota_weight' => 2]);

    $response = $this->actingAs(usageReportOwner())->getJson('/settings/instance/usage?group_by=category');

    $response->assertOk()->assertJsonPath('group_by', 'category');
    expect(collect($response->json('data'))->firstWhere('label', 'publish')['total_quota'])->toBe(6);
});
