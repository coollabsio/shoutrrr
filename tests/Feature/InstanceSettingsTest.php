<?php

use App\Enums\InstanceRole;
use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\UsageEvent;
use App\Models\UsagePeriodCounter;
use App\Models\User;
use App\Models\Workspace;
use App\Support\InstanceSettings;
use App\Support\UsageOperation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
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
            'quote_tweets_enabled' => false,
            'linkedin_community_management_enabled' => true,
        ])
        ->assertRedirect();

    expect(app(InstanceSettings::class)->registrationsEnabled())->toBeFalse()
        ->and(app(InstanceSettings::class)->workspaceCreationEnabled())->toBeFalse()
        ->and(app(InstanceSettings::class)->linkedinCommunityManagementEnabled())->toBeTrue();
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
            'quote_tweets_enabled' => false,
            'linkedin_community_management_enabled' => false,
        ])
        ->assertRedirect();

    expect(app(InstanceSettings::class)->workspaceCreationEnabled())->toBeFalse();
});

test('linkedin engagement polling is gated on the community management setting', function () {
    $settings = app(InstanceSettings::class);

    // Off by default: LinkedIn must never be polled for replies (every fetch 403s
    // without the restricted r_member_social_feed scope).
    expect($settings->engagementPollingEnabled(Platform::LinkedIn))->toBeFalse();

    $settings->update(['linkedin_community_management_enabled' => true]);

    expect($settings->engagementPollingEnabled(Platform::LinkedIn))->toBeTrue();
});

test('instance owner can view polling settings', function () {
    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->get(route('instance-settings.polling'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/instance-polling')
            ->where('settings.engagement.enabled.x', true)
            ->where('settings.engagement.enabled.bluesky', true)
            ->where('settings.engagement.x', 360)
            ->where('settings.engagement.bluesky', 15)
            ->where('settings.post_metrics.enabled.x', true)
            ->where('settings.post_metrics.enabled.linkedin', true)
            ->where('settings.post_metrics.x', 360)
            ->where('settings.post_metrics.linkedin', 15)
            ->where('settings.account_metrics.enabled.x', true)
            ->where('settings.account_metrics.enabled.bluesky', true)
            ->where('settings.account_metrics.x', 1440)
            ->where('settings.account_metrics.bluesky', 1440)
            ->where('settings.account_metrics.linkedin', 1440));
});

test('instance owner can view usage details', function () {
    config()->set('services.x.bearer_token', 'x-bearer-token');

    $owner = User::factory()->instanceOwner()->create();
    $workspace = Workspace::factory()->create(['name' => 'Usage Workspace']);

    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id,
        'category' => UsageCategory::Publish->value,
        'platform' => Platform::Bluesky->value,
        'operation' => UsageOperation::POST,
        'event_count' => 2,
        'total_quota' => 2,
    ]);

    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id,
        'period_start' => Date::now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        'period_end' => Date::now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
        'category' => UsageCategory::Publish->value,
        'platform' => Platform::Bluesky->value,
        'operation' => UsageOperation::POST,
        'event_count' => 1,
        'total_quota' => 1,
    ]);

    UsageEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'category' => UsageCategory::Publish->value,
        'platform' => Platform::Bluesky->value,
        'operation' => UsageOperation::POST,
        'quota_weight' => 1,
        'succeeded' => false,
        'meta' => ['status' => 429],
    ]);

    $this->actingAs($owner)
        ->get(route('instance-settings.usage'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/instance-usage')
            ->where('workspace_options.0.id', $workspace->id)
            ->where('workspace_options.0.name', 'Usage Workspace')
            ->where('platforms.0.value', 'bluesky')
            ->where('filters.workspace', null)
            ->where('filters.platform', null)
            ->where('x_usage_available', true)
            ->where('summaries.0.workspace.name', 'Usage Workspace')
            ->where('summaries.0.current_total_quota', 2)
            ->where('summaries.0.previous_total_quota', 1)
            ->where('summaries.0.quota_delta', 1)
            ->where('summaries.0.posts_quota', 2)
            ->where('counters.0.workspace.name', 'Usage Workspace')
            ->where('counters.0.platform', 'bluesky')
            ->where('counters.0.event_count', 2)
            ->where('error_events.0.operation', UsageOperation::POST)
            ->where('error_events.0.meta.status', 429));
});

test('instance usage marks x api usage unavailable without bearer token', function () {
    config()->set('services.x.bearer_token', '');

    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->get(route('instance-settings.usage'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/instance-usage')
            ->where('x_usage_available', false));
});

test('instance usage can be filtered by workspace', function () {
    $owner = User::factory()->instanceOwner()->create();
    $shownWorkspace = Workspace::factory()->create(['name' => 'Shown Workspace']);
    $hiddenWorkspace = Workspace::factory()->create(['name' => 'Hidden Workspace']);

    UsagePeriodCounter::factory()->create(['workspace_id' => $shownWorkspace->id]);
    UsagePeriodCounter::factory()->create(['workspace_id' => $hiddenWorkspace->id]);
    UsageEvent::factory()->create(['workspace_id' => $shownWorkspace->id, 'succeeded' => false]);
    UsageEvent::factory()->create(['workspace_id' => $hiddenWorkspace->id, 'succeeded' => false]);

    $this->actingAs($owner)
        ->get(route('instance-settings.usage', ['workspace' => $shownWorkspace->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.workspace', $shownWorkspace->id)
            ->has('counters', 1)
            ->where('counters.0.workspace.id', $shownWorkspace->id)
            ->has('error_events', 1)
            ->where('error_events.0.workspace.id', $shownWorkspace->id));
});

test('instance usage does not override shared workspace shell props', function () {
    $owner = User::factory()->instanceOwner()->create();

    $response = $this->actingAs($owner)
        ->get(route('instance-settings.usage'))
        ->assertOk();

    expect($response->inertiaProps())->toHaveKey('workspace_options')
        ->and($response->inertiaProps('workspaces'))->toHaveKeys(['enabled', 'current', 'all']);
});

test('instance usage can be filtered by platform', function () {
    $owner = User::factory()->instanceOwner()->create();
    $workspace = Workspace::factory()->create();

    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Bluesky->value,
        'total_quota' => 3,
    ]);
    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
        'total_quota' => 5,
    ]);
    UsageEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Bluesky->value,
        'succeeded' => false,
    ]);
    UsageEvent::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
        'succeeded' => false,
    ]);

    $this->actingAs($owner)
        ->get(route('instance-settings.usage', ['platform' => Platform::Bluesky->value]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.platform', Platform::Bluesky->value)
            ->has('summaries', 1)
            ->where('summaries.0.current_total_quota', 3)
            ->has('counters', 1)
            ->where('counters.0.platform', Platform::Bluesky->value)
            ->has('error_events', 1)
            ->where('error_events.0.platform', Platform::Bluesky->value));
});

test('instance usage includes x pricing estimates', function () {
    config(['usage_pricing.platforms.x.currency' => 'EUR']);

    $owner = User::factory()->instanceOwner()->create();
    $workspace = Workspace::factory()->create();

    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
        'operation' => UsageOperation::POST,
        'event_count' => 2,
        'total_quota' => 2,
    ]);
    UsagePeriodCounter::factory()->create([
        'workspace_id' => $workspace->id,
        'period_start' => Date::now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        'period_end' => Date::now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
        'platform' => Platform::X->value,
        'operation' => UsageOperation::POST,
        'event_count' => 1,
        'total_quota' => 1,
    ]);

    $this->actingAs($owner)
        ->get(route('instance-settings.usage', ['platform' => Platform::X->value]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pricing_source', 'https://developer.x.com/#pricing')
            ->where('pricing_currency', 'EUR')
            ->where('summaries.0.current_estimated_cost_usd', 0.03)
            ->where('summaries.0.previous_estimated_cost_usd', 0.015)
            ->where('summaries.0.estimated_cost_delta_usd', 0.015)
            ->where('counters.0.pricing.resource', 'post_create')
            ->where('counters.0.pricing.unit_cost_usd', 0.015)
            ->where('counters.0.pricing.estimated_cost_usd', 0.03));
});

test('instance owner can fetch x api usage', function () {
    config()->set('services.x.bearer_token', 'x-bearer-token');
    Cache::flush();

    $owner = User::factory()->instanceOwner()->create();

    Http::fake([
        'https://api.x.com/2/usage/tweets*' => Http::response([
            'data' => [
                'project_id' => '1234567890',
                'project_usage' => 15420,
                'project_cap' => 2000000,
                'cap_reset_day' => 12,
            ],
        ]),
    ]);

    $this->actingAs($owner)
        ->getJson(route('instance-settings.usage.x'))
        ->assertOk()
        ->assertJsonPath('data.project_usage', 15420)
        ->assertJsonPath('source', 'https://api.x.com/2/usage/tweets');

    $this->actingAs($owner)
        ->getJson(route('instance-settings.usage.x'))
        ->assertOk()
        ->assertJsonPath('data.project_usage', 15420)
        ->assertJsonPath('source', 'https://api.x.com/2/usage/tweets');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.x.com/2/usage/tweets?days=7&usage.fields=cap_reset_day%2Cdaily_client_app_usage%2Cdaily_project_usage%2Cproject_cap%2Cproject_id%2Cproject_usage'
        && $request->hasHeader('Authorization', 'Bearer x-bearer-token'));
    Http::assertSentCount(1);
});

test('x api usage fetch requires a configured bearer token', function () {
    config()->set('services.x.bearer_token', '');

    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->getJson(route('instance-settings.usage.x'))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Configure X_BEARER_TOKEN before fetching X API usage.');
});

test('regular users cannot fetch x api usage', function () {
    config()->set('services.x.bearer_token', 'x-bearer-token');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('instance-settings.usage.x'))
        ->assertForbidden();
});

test('analytics exposes disabled metric polling groups', function () {
    app(InstanceSettings::class)->update([
        'post_metrics_polling_enabled' => false,
        'account_metrics_polling_enabled' => false,
    ]);

    $workspace = Workspace::factory()->create();
    $owner = User::factory()->instanceOwner()->create(['current_workspace_id' => $workspace->id]);
    $owner->workspaceMemberships()->create([
        'workspace_id' => $workspace->id,
        'role' => 'owner',
    ]);

    $this->actingAs($owner)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('analytics/index')
            ->where('polling.post_metrics_enabled.x', false)
            ->where('polling.post_metrics_enabled.bluesky', false)
            ->where('polling.account_metrics_enabled.x', false)
            ->where('polling.account_metrics_enabled.bluesky', false));
});

test('regular users cannot view usage details', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('instance-settings.usage'))
        ->assertForbidden();
});

test('instance owner can update polling settings', function () {
    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->put(route('instance-settings.polling.update'), [
            'engagement' => [
                'enabled' => ['x' => false, 'bluesky' => true, 'linkedin' => true, 'facebook' => true, 'instagram' => true, 'threads' => true],
                'x' => 720, 'bluesky' => 30, 'linkedin' => 120, 'facebook' => 15, 'instagram' => 15, 'threads' => 15,
            ],
            'post_metrics' => [
                'enabled' => ['x' => false, 'bluesky' => true, 'facebook' => true, 'instagram' => true, 'threads' => true, 'discord' => true],
                'x' => 1440, 'bluesky' => 45, 'facebook' => 15, 'instagram' => 15, 'threads' => 15, 'discord' => 90,
            ],
            'account_metrics' => [
                'enabled' => ['x' => false, 'bluesky' => true, 'facebook' => true, 'instagram' => true, 'threads' => true],
                'x' => 1440, 'bluesky' => 240, 'facebook' => 15, 'instagram' => 15, 'threads' => 15,
            ],
            'metrics_enabled' => true,
            'engagement_enabled' => true,
        ])
        ->assertRedirect();

    $polling = app(InstanceSettings::class)->polling();

    // Every sent platform's enabled flag and interval persisted correctly, section by section.
    expect($polling['engagement']['enabled'])->toMatchArray([
        'x' => false, 'bluesky' => true, 'linkedin' => true, 'facebook' => true, 'instagram' => true, 'threads' => true,
    ])
        ->and($polling['engagement'])->toMatchArray([
            'x' => 720, 'bluesky' => 30, 'linkedin' => 120, 'facebook' => 15, 'instagram' => 15, 'threads' => 15,
        ])
        ->and($polling['post_metrics']['enabled'])->toMatchArray([
            'x' => false, 'bluesky' => true, 'facebook' => true, 'instagram' => true, 'threads' => true, 'discord' => true,
        ])
        ->and($polling['post_metrics'])->toMatchArray([
            'x' => 1440, 'bluesky' => 45, 'facebook' => 15, 'instagram' => 15, 'threads' => 15, 'discord' => 90,
        ])
        ->and($polling['account_metrics']['enabled'])->toMatchArray([
            'x' => false, 'bluesky' => true, 'facebook' => true, 'instagram' => true, 'threads' => true,
        ])
        ->and($polling['account_metrics'])->toMatchArray([
            'x' => 1440, 'bluesky' => 240, 'facebook' => 15, 'instagram' => 15, 'threads' => 15,
        ]);

    // LinkedIn is not a configurable metrics platform, so it keeps its read default
    // (enabled + per-platform fallback) even though we never sent it.
    expect($polling['post_metrics']['enabled']['linkedin'])->toBeTrue()
        ->and($polling['account_metrics']['enabled']['linkedin'])->toBeTrue();
});

test('instance owner can toggle the metrics and engagement master switches from the polling page', function () {
    $owner = User::factory()->instanceOwner()->create();

    $this->actingAs($owner)
        ->put(route('instance-settings.polling.update'), [
            'engagement' => [
                'enabled' => ['x' => true, 'bluesky' => true, 'linkedin' => true, 'facebook' => true, 'instagram' => true, 'threads' => true],
                'x' => 360, 'bluesky' => 15, 'linkedin' => 15, 'facebook' => 15, 'instagram' => 15, 'threads' => 15,
            ],
            'post_metrics' => [
                'enabled' => ['x' => true, 'bluesky' => true, 'facebook' => true, 'instagram' => true, 'threads' => true, 'discord' => true],
                'x' => 360, 'bluesky' => 15, 'facebook' => 15, 'instagram' => 15, 'threads' => 15, 'discord' => 15,
            ],
            'account_metrics' => [
                'enabled' => ['x' => true, 'bluesky' => true, 'facebook' => true, 'instagram' => true, 'threads' => true],
                'x' => 1440, 'bluesky' => 1440, 'facebook' => 15, 'instagram' => 15, 'threads' => 15,
            ],
            'metrics_enabled' => false,
            'engagement_enabled' => false,
        ])
        ->assertRedirect();

    expect(app(InstanceSettings::class)->metricsEnabled())->toBeFalse()
        ->and(app(InstanceSettings::class)->engagementEnabled())->toBeFalse();
});

test('regular users cannot view polling settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('instance-settings.polling'))
        ->assertForbidden();
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
