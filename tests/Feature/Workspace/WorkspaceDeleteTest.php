<?php

use App\Exceptions\CannotDeleteInitialWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiConnectionException;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

/**
 * Bind a fake Stripe client so `cancelNow()` never leaves the test process, and
 * return the subscription service so callers can assert on `cancel()` calls.
 */
function fakeStripeSubscriptions(): SubscriptionService
{
    $service = Mockery::mock(SubscriptionService::class);

    $client = Mockery::mock(StripeClient::class);
    $client->shouldReceive('getService')->with('subscriptions')->andReturn($service);

    app()->bind(StripeClient::class, fn (): StripeClient => $client);

    return $service;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function subscriptionFor(Workspace $workspace, array $attributes = []): Subscription
{
    return Subscription::create([
        'workspace_id' => $workspace->id,
        'type' => 'default',
        'stripe_id' => 'sub_'.Str::random(10),
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
        ...$attributes,
    ]);
}

test('owner cannot delete their last workspace', function () {
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->delete(route('workspaces.destroy', $workspace))->assertSessionHasErrors('workspace');

    $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    $this->assertSame($workspace->id, $owner->fresh()->current_workspace_id);
});

test('workspace settings disables deletion for the last workspace', function () {
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->get(route('settings.workspace'))
        ->assertInertia(fn ($page) => $page
            ->where('canDelete', false)
        );
});

test('workspace settings disables deletion for the initial workspace on a cloud instance', function () {
    config(['subscriptions.enabled' => true]);
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->get(route('settings.workspace'))
        ->assertInertia(fn ($page) => $page
            ->where('canDelete', false)
            ->where('deleteDisabledReason', 'The initial workspace of this instance cannot be deleted.')
        );
});

test('owner can delete workspace and memberships cascade when another workspace remains', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->delete(route('workspaces.destroy', $workspace))->assertRedirect();

    $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
    $this->assertDatabaseMissing('workspace_memberships', ['workspace_id' => $workspace->id]);
    $this->assertSame($other->id, $owner->fresh()->current_workspace_id);
});

test('non owner cannot delete workspace', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $member->id]);

    $this->actingAs($member)->delete(route('workspaces.destroy', $workspace))->assertForbidden();

    $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
});

test('the first workspace created on the instance is flagged as initial', function () {
    $first = Workspace::factory()->create();
    $second = Workspace::factory()->create();

    expect($first->refresh()->is_initial)->toBeTrue()
        ->and($second->refresh()->is_initial)->toBeFalse();
});

test('the initial workspace cannot be deleted on a cloud instance', function () {
    config(['subscriptions.enabled' => true]);
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $other->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->delete(route('workspaces.destroy', $workspace))->assertSessionHasErrors('workspace');

    $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);

    // Model-level guard is loud when the controller is bypassed.
    expect(fn () => $workspace->refresh()->delete())->toThrow(CannotDeleteInitialWorkspace::class);
    $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
});

test('deleting current workspace reassigns to another membership', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $a->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $a->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $b->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)->delete(route('workspaces.destroy', $a))->assertRedirect();

    $this->assertSame($b->id, $owner->fresh()->current_workspace_id);
});

test('deleting a workspace cancels its live stripe subscription', function () {
    $initial = Workspace::factory()->create();
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $initial->id, 'user_id' => $owner->id]);

    $subscription = subscriptionFor($workspace);

    fakeStripeSubscriptions()
        ->shouldReceive('cancel')
        ->once()
        ->with($subscription->stripe_id, Mockery::type('array'));

    $this->actingAs($owner)->delete(route('workspaces.destroy', $workspace))->assertRedirect();

    $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
    $this->assertDatabaseMissing('subscriptions', ['id' => $subscription->id]);
});

test('deleting a workspace cancels a past due subscription that the active scope would skip', function () {
    $workspace = Workspace::factory()->create();
    $subscription = subscriptionFor($workspace, ['stripe_status' => 'past_due']);

    fakeStripeSubscriptions()
        ->shouldReceive('cancel')
        ->once()
        ->with($subscription->stripe_id, Mockery::type('array'));

    $workspace->delete();

    $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
});

test('deleting a workspace leaves already cancelled subscriptions alone', function () {
    $workspace = Workspace::factory()->create();
    subscriptionFor($workspace, ['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);
    subscriptionFor($workspace, ['stripe_status' => 'incomplete_expired']);

    fakeStripeSubscriptions()->shouldNotReceive('cancel');

    $workspace->delete();

    $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
});

test('a stripe failure aborts the workspace delete instead of orphaning the subscription', function () {
    $initial = Workspace::factory()->create();
    $workspace = Workspace::factory()->create();
    $owner = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $initial->id, 'user_id' => $owner->id]);

    $subscription = subscriptionFor($workspace);

    fakeStripeSubscriptions()
        ->shouldReceive('cancel')
        ->once()
        ->andThrow(new ApiConnectionException('Stripe is down'));

    expect(fn () => $workspace->delete())->toThrow(ApiConnectionException::class);

    $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
});
