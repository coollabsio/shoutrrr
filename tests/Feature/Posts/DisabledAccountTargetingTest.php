<?php

use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Posts\DraftService;
use Illuminate\Support\Facades\Context;
use Inertia\Testing\AssertableInertia as Assert;

function ownerWithDisabledAccount(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Owner,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();

    $enabled = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'handle' => '@enabled',
        'connected_by_user_id' => $user->id,
    ]);
    $disabled = ConnectedAccount::factory()->disabled()->create([
        'workspace_id' => $workspace->id,
        'handle' => '@disabled',
        'connected_by_user_id' => $user->id,
    ]);

    return [$user, $workspace, $enabled, $disabled];
}

test('draft targeting never snapshots a disabled account', function () {
    [$user, $workspace, $enabled, $disabled] = ownerWithDisabledAccount();

    Context::add('workspace_id', $workspace->id);

    $ids = app(DraftService::class)->resolveDestinationAccountIds(
        $workspace->id,
        ['kind' => 'all'],
    );

    expect($ids)->toBe([$enabled->id]);
});

test('the composer and shell exclude disabled accounts', function () {
    [$user, $workspace, $enabled, $disabled] = ownerWithDisabledAccount();

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'author_id' => $user->id,
    ]);

    test()->actingAs($user)->get("/posts/{$post->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('compose/index')
            ->has('accounts', 1)
            ->where('accounts.0.handle', '@enabled')
            ->has('shell.accounts', 1)
            ->where('shell.accounts.0.handle', '@enabled'),
        );
});
