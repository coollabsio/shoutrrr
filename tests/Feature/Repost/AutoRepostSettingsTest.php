<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    $this->actingAs($this->user);
});

test('enabling auto-repost merges into capabilities without clobbering other keys', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_by_user_id' => $this->user->id,
        'platform' => Platform::X->value,
        'capabilities' => ['x_premium' => true, 'max_text_length' => 4000],
    ]);

    $this->patch(route('accounts.auto-repost', $account), ['enabled' => true, 'min_percentile' => 0.7])
        ->assertRedirect();

    $account->refresh();
    expect($account->capabilities['auto_repost']['enabled'])->toBeTrue()
        ->and($account->capabilities['auto_repost']['min_percentile'])->toBe(0.7)
        ->and($account->capabilities['x_premium'])->toBeTrue()
        ->and($account->capabilities['max_text_length'])->toBe(4000);
});

test('disabling then re-enabling auto-repost without min_percentile preserves the stored value', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_by_user_id' => $this->user->id,
        'platform' => Platform::X->value,
    ]);

    $this->patch(route('accounts.auto-repost', $account), ['enabled' => true, 'min_percentile' => 0.7])
        ->assertRedirect();

    $this->patch(route('accounts.auto-repost', $account), ['enabled' => false])
        ->assertRedirect();

    $account->refresh();
    expect($account->capabilities['auto_repost']['enabled'])->toBeFalse()
        ->and($account->capabilities['auto_repost']['min_percentile'])->toBe(0.7);

    $this->patch(route('accounts.auto-repost', $account), ['enabled' => true])
        ->assertRedirect();

    $account->refresh();
    expect($account->capabilities['auto_repost']['enabled'])->toBeTrue()
        ->and($account->capabilities['auto_repost']['min_percentile'])->toBe(0.7);
});

test('auto-repost cannot be enabled on an unsupported platform', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_by_user_id' => $this->user->id,
        'platform' => Platform::Instagram->value,
    ]);

    $this->patch(route('accounts.auto-repost', $account), ['enabled' => true])
        ->assertRedirect();

    expect($account->fresh()->autoRepostEnabled())->toBeFalse();
});

test('a member without account-manage permission cannot update auto-repost', function (): void {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_by_user_id' => $this->user->id,
        'platform' => Platform::X->value,
    ]);

    $member = User::factory()->create();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $member->id,
        'role' => WorkspaceRole::Member,
    ]);
    $member->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    $this->actingAs($member)->patch(route('accounts.auto-repost', $account), ['enabled' => true])
        ->assertForbidden();

    expect($account->fresh()->autoRepostEnabled())->toBeFalse();
});
