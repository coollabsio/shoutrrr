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

it('preserves saved handles as submitted', function () {
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
        ->assertJsonPath('mention.handles.x', 'taylorotwell');
});

it('preserves saved display text for people without a platform mention', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->postJson(route('workspace-mentions.store'), [
            'name' => '@taylor',
            'handles' => ['linkedin' => 'Taylor Otwell'],
        ])
        ->assertSuccessful()
        ->assertJsonPath('mention.handles.linkedin', 'Taylor Otwell');
});

it('round-trips Meta platform handles on create', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->postJson(route('workspace-mentions.store'), [
            'name' => '@company-x',
            'handles' => [
                'facebook' => 'Company X',
                'instagram' => 'companyx',
                'threads' => 'companyx',
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('mention.handles.facebook', 'Company X')
        ->assertJsonPath('mention.handles.instagram', 'companyx')
        ->assertJsonPath('mention.handles.threads', 'companyx');

    expect(WorkspaceMention::query()->first()->handles)
        ->toBe(['facebook' => 'Company X', 'instagram' => 'companyx', 'threads' => 'companyx']);
});

it('persists an edited Meta plain-text value on update', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);
    WorkspaceMention::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => '@company-x',
        'handles' => ['facebook' => 'Company X'],
    ]);

    $this->actingAs($user)
        ->postJson(route('workspace-mentions.store'), [
            'name' => '@company-x',
            'handles' => ['facebook' => 'Company X Inc.'],
        ])
        ->assertSuccessful()
        ->assertJsonPath('mention.handles.facebook', 'Company X Inc.');

    expect(WorkspaceMention::query()->where('name', '@company-x')->first()->handles)
        ->toBe(['facebook' => 'Company X Inc.']);
});

it('deletes a saved mention for the current workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);
    $mention = WorkspaceMention::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => '@company-x',
        'handles' => ['x' => '@companyx'],
    ]);

    $this->actingAs($user)
        ->deleteJson(route('workspace-mentions.destroy', $mention))
        ->assertSuccessful();

    expect(WorkspaceMention::query()->withoutGlobalScopes()->find($mention->id))
        ->toBeNull();
});

it('cannot delete a mention belonging to another workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $otherWorkspace = Workspace::factory()->create();
    $foreignMention = WorkspaceMention::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'name' => '@foreign',
    ]);

    $this->actingAs($user)
        ->deleteJson(route('workspace-mentions.destroy', $foreignMention))
        ->assertNotFound();

    expect(WorkspaceMention::query()->withoutGlobalScopes()->find($foreignMention->id))
        ->not->toBeNull();
});

it('normalizes a saved LinkedIn org reference into a canonical URN', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->postJson(route('workspace-mentions.store'), [
            'name' => '@coolify',
            'handles' => [
                'linkedin' => 'Coolify',
                'linkedin_urn' => 'https://www.linkedin.com/company/12345/',
            ],
        ])
        ->assertSuccessful()
        ->assertJsonPath('mention.handles.linkedin', 'Coolify')
        ->assertJsonPath('mention.handles.linkedin_urn', 'urn:li:organization:12345');
});

it('drops an unresolvable LinkedIn org reference (vanity slug needs the lookup API)', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $this->actingAs($user)
        ->postJson(route('workspace-mentions.store'), [
            'name' => '@coolify',
            'handles' => [
                'linkedin' => 'Coolify',
                'linkedin_urn' => 'coolify',
            ],
        ])
        ->assertSuccessful()
        ->assertJsonMissingPath('mention.handles.linkedin_urn');
});
