<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Posts\DraftService;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\Context;

/**
 * @return array{0: string, 1: ConnectedAccount, 2: ConnectedAccount}
 */
function seedTwoPlatformWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $xAccount = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]);
    $blueskyAccount = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Bluesky->value,
    ]);

    return [$workspace->id, $xAccount, $blueskyAccount];
}

it('excludes frozen-platform accounts when resolving a draft destination', function () {
    [$workspaceId, $xAccount, $blueskyAccount] = seedTwoPlatformWorkspace();

    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    $ids = app(DraftService::class)->resolveDestinationAccountIds($workspaceId, ['kind' => 'all']);

    expect($ids)->not->toContain($xAccount->id);
    expect($ids)->toContain($blueskyAccount->id);
});

it('excludes frozen-platform accounts from the composer accounts prop', function () {
    [$workspaceId, $xAccount, $blueskyAccount] = seedTwoPlatformWorkspace();

    app(InstanceSettings::class)->update(['platforms_enabled' => ['x' => false]]);

    $user = User::query()->where('current_workspace_id', $workspaceId)->firstOrFail();
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspaceId,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $post = app(DraftService::class)->createDraft($workspaceId, $user, ['kind' => 'all'], ['hello']);

    $response = $this->actingAs($user)->get(route('posts.show', $post));

    $response->assertInertia(fn ($page) => $page
        ->where('accounts', fn (mixed $accounts): bool => collect($accounts)->pluck('id')->contains($blueskyAccount->id)
            && ! collect($accounts)->pluck('id')->contains($xAccount->id)));
});
