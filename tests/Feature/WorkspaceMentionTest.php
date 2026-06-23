<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMention;
use Illuminate\Support\Facades\Context;

it('saves a mention library item for the current workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->postJson(route('workspace-mentions.store'), [
            'name' => '@taylor',
            'handles' => [
                'x' => '@taylorotwell',
                'bluesky' => '@taylor.bsky.social',
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('mention.name', '@taylor')
        ->assertJsonPath('mention.handles.x', '@taylorotwell');

    expect(WorkspaceMention::query()->where('workspace_id', $workspace->id)->first())
        ->not->toBeNull();
});

it('updates an existing saved mention by workspace and name', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);
    WorkspaceMention::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => '@taylor',
        'handles' => ['x' => '@old'],
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace-mentions.store'), [
            'name' => '@taylor',
            'handles' => ['x' => '@new'],
        ])
        ->assertSuccessful();

    expect(WorkspaceMention::query()->where('workspace_id', $workspace->id)->where('name', '@taylor')->get())
        ->toHaveCount(1)
        ->and(WorkspaceMention::query()->first()->handles)->toBe(['x' => '@new']);
});

it('preadds at signs to saved handles', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->postJson(route('workspace-mentions.store'), [
            'name' => '@taylor',
            'handles' => ['x' => 'taylorotwell'],
        ])
        ->assertSuccessful()
        ->assertJsonPath('mention.handles.x', '@taylorotwell');
});
