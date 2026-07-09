<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Bus;
use Laravel\Cashier\Subscription;

beforeEach(function () {
    config(['subscriptions.enabled' => true]);
});

function actingInWorkspace(Workspace $workspace): User
{
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    test()->actingAs($user);

    return $user;
}

function addXAccount(Workspace $workspace): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]);
}

function subscribeWorkspaceForCloudGate(Workspace $workspace): void
{
    $workspace->forceFill(['stripe_id' => 'cus_test_'.$workspace->id])->save();

    Subscription::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'default',
        'stripe_id' => 'sub_test_'.fake()->unique()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => config('subscriptions.stripe_price_id'),
        'quantity' => 1,
    ]);
}

test('the first cloud workspace can use the app without a subscription', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    actingInWorkspace($workspace);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => auth()->id(),
        'status' => PostStatus::Draft,
    ]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => addXAccount($workspace)->id,
    ]);

    $this->postJson("/posts/{$post->id}/publish")->assertOk();
});

test('additional cloud workspaces can draft without a subscription', function () {
    Workspace::factory()->create();
    $workspace = Workspace::factory()->create();
    actingInWorkspace($workspace);
    addXAccount($workspace);

    $this->postJson('/posts', [
        'segments' => ['draft allowed'],
        'destination' => ['kind' => 'all'],
    ])->assertCreated();
});

test('additional cloud workspaces must subscribe before publishing', function () {
    Workspace::factory()->create();
    $workspace = Workspace::factory()->create();
    actingInWorkspace($workspace);
    addXAccount($workspace);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => auth()->id(),
        'status' => PostStatus::Draft,
    ]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => addXAccount($workspace)->id,
    ]);

    $this->postJson("/posts/{$post->id}/publish")
        ->assertPaymentRequired()
        ->assertJsonPath('message', 'Subscribe to publish this post.')
        ->assertJsonPath('billing_url', route('billing.index'));
});

test('subscribed additional cloud workspaces can publish', function () {
    Bus::fake();
    Workspace::factory()->create();
    $workspace = Workspace::factory()->create();
    actingInWorkspace($workspace);
    subscribeWorkspaceForCloudGate($workspace);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => auth()->id(),
        'status' => PostStatus::Draft,
    ]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => addXAccount($workspace)->id,
    ]);

    $this->postJson("/posts/{$post->id}/publish")->assertOk();
});

test('additional cloud workspaces must subscribe before queueing', function () {
    Workspace::factory()->create();
    $workspace = Workspace::factory()->create();
    actingInWorkspace($workspace);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => auth()->id(),
        'status' => PostStatus::Draft,
    ]);

    $this->postJson("/posts/{$post->id}/queue")
        ->assertPaymentRequired()
        ->assertJsonPath('message', 'Subscribe to publish this post.');
});

test('additional cloud workspaces must subscribe before scheduling', function () {
    Workspace::factory()->create();
    $workspace = Workspace::factory()->create();
    actingInWorkspace($workspace);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => auth()->id(),
        'status' => PostStatus::Draft,
    ]);

    $this->putJson("/posts/{$post->id}/schedule", ['scheduled_at' => '2030-01-01T09:00:00+00:00'])
        ->assertPaymentRequired()
        ->assertJsonPath('message', 'Subscribe to publish this post.');
});
