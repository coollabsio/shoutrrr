<?php

use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\SyncPipeline;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Support\Onboarding\OnboardingPresenter;

function ownerWithWorkspaceForOnboarding(): array
{
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->owner()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    return [$workspace, $user];
}

test('owner sees all five steps, none done on a fresh workspace', function () {
    [$workspace, $user] = ownerWithWorkspaceForOnboarding();

    $data = OnboardingPresenter::make($workspace, $user);

    expect($data['steps'])->toHaveCount(5)
        ->and(collect($data['steps'])->pluck('key')->all())
        ->toBe(['connect_account', 'enable_sync', 'first_post', 'timezone', 'invite_teammate'])
        ->and(collect($data['steps'])->every(fn ($s) => $s['done'] === false))->toBeTrue()
        ->and($data['complete'])->toBeFalse();
});

test('read-only member sees no steps and is never complete', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create([ // default role = member
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
    ]);

    $data = OnboardingPresenter::make($workspace, $member);

    expect($data['steps'])->toHaveCount(0)
        ->and($data['complete'])->toBeFalse();
});

test('non-timezone steps derive done-state from real data', function () {
    [$workspace, $user] = ownerWithWorkspaceForOnboarding();

    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $destination = ConnectedAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    SyncPipeline::factory()->create([
        'workspace_id' => $workspace->id,
        'source_connected_account_id' => $source->id,
    ])->destinations()->sync([$destination->id]);
    Post::factory()->create(['workspace_id' => $workspace->id, 'author_id' => $user->id]);
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);

    $steps = collect(OnboardingPresenter::make($workspace->fresh(), $user)['steps'])
        ->keyBy('key');

    expect($steps['connect_account']['done'])->toBeTrue()
        ->and($steps['connect_account']['clickToComplete'])->toBeFalse()
        ->and($steps['enable_sync']['done'])->toBeTrue()
        ->and($steps['first_post']['done'])->toBeTrue()
        ->and($steps['invite_teammate']['done'])->toBeTrue()
        // Timezone is click-to-complete: not done just because data exists.
        ->and($steps['timezone']['done'])->toBeFalse()
        ->and($steps['timezone']['clickToComplete'])->toBeTrue();
});

test('timezone step completes only when recorded in progress', function () {
    [$workspace, $user] = ownerWithWorkspaceForOnboarding();

    // Everything except timezone is satisfied by data.
    $source = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    SyncPipeline::factory()->create([
        'workspace_id' => $workspace->id,
        'source_connected_account_id' => $source->id,
    ]);
    Post::factory()->create(['workspace_id' => $workspace->id, 'author_id' => $user->id]);
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);

    expect(OnboardingPresenter::make($workspace->fresh(), $user)['complete'])->toBeFalse();

    $workspace->forceFill(['onboarding_progress' => ['timezone']])->save();

    $steps = collect(OnboardingPresenter::make($workspace->fresh(), $user)['steps'])->keyBy('key');
    expect($steps['timezone']['done'])->toBeTrue()
        ->and(OnboardingPresenter::make($workspace->fresh(), $user)['complete'])->toBeTrue();
});

test('welcomed and dismissed mirror the workspace timestamps', function () {
    [$workspace, $user] = ownerWithWorkspaceForOnboarding();
    $workspace->forceFill(['onboarding_welcomed_at' => now(), 'onboarding_dismissed_at' => null])->save();

    $data = OnboardingPresenter::make($workspace->fresh(), $user);

    expect($data['welcomed'])->toBeTrue()
        ->and($data['dismissed'])->toBeFalse();
});

test('enable sync counts as done when recorded in progress, even without a pipeline', function () {
    [$workspace, $user] = ownerWithWorkspaceForOnboarding();

    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    Post::factory()->create(['workspace_id' => $workspace->id, 'author_id' => $user->id]);
    WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->id]);
    $workspace->forceFill(['onboarding_progress' => ['timezone', 'enable_sync']])->save();

    $data = OnboardingPresenter::make($workspace->fresh(), $user);

    expect($workspace->syncPipelines()->exists())->toBeFalse()
        ->and(collect($data['steps'])->keyBy('key')['enable_sync']['done'])->toBeTrue()
        ->and($data['complete'])->toBeTrue();
});
