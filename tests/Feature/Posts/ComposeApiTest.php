<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMention;
use Inertia\Testing\AssertableInertia;

function actingMember(int $accounts = 2): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);

    $list = collect(range(1, $accounts))->map(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]));

    return [$user, $workspace, $list];
}

test('POST /posts lazily creates a draft and returns its view as JSON', function () {
    [$user, $workspace, $accounts] = actingMember(2);

    $response = test()->postJson('/posts', [
        'base_text' => 'first draft',
        'segments' => ['first draft'],
        'destination' => ['kind' => 'all'],
    ]);

    $response->assertCreated()
        ->assertJsonPath('post.base_text', 'first draft')
        ->assertJsonPath('post.status', 'draft')
        ->assertJsonCount(2, 'post.targets');

    expect(Post::withoutGlobalScopes()->count())->toBe(1);
});

test('POST /posts accepts a custom accounts destination', function () {
    [$user, $workspace, $accounts] = actingMember(3);

    test()->postJson('/posts', [
        'base_text' => 'custom draft',
        'segments' => ['custom draft'],
        'destination' => ['kind' => 'accounts', 'ids' => [$accounts[0]->id, $accounts[2]->id]],
    ])->assertCreated()
        ->assertJsonPath('post.destination.kind', 'accounts')
        ->assertJsonPath('post.destination.ids', collect([$accounts[0]->id, $accounts[2]->id])->sort()->values()->all())
        ->assertJsonCount(2, 'post.targets');
});

test('PUT /posts/{post} autosaves edits and returns the new baseline', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $created = test()->postJson('/posts', ['base_text' => 'a', 'segments' => ['a'], 'destination' => ['kind' => 'all']])->json('post');

    $response = test()->putJson("/posts/{$created['id']}", [
        'base_text' => 'edited',
        'segments' => ['edited'],
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $accounts[0]->id, 'auto_split' => true]],
        'expected_updated_at' => $created['updated_at'],
    ]);

    $response->assertOk()->assertJsonPath('post.base_text', 'edited');
});

test('POST /posts accepts the real client payload with segments and no base_text', function () {
    [$user, $workspace, $accounts] = actingMember(2);

    // Mirrors use-autosave.ts createPost(): {segments, mentions, destination} — no base_text.
    $response = test()->postJson('/posts', [
        'destination' => ['kind' => 'all'],
        'segments' => ['first post', 'second post'],
        'mentions' => [],
    ]);

    $response->assertCreated()
        ->assertJsonPath('post.segments', ['first post', 'second post'])
        ->assertJsonPath('post.base_text', "first post\nsecond post");
});

test('POST /posts rejects an over-long linkedin_urn handle', function () {
    [$user, $workspace, $accounts] = actingMember(1);

    test()->postJson('/posts', [
        'destination' => ['kind' => 'all'],
        'segments' => ['hello'],
        'mentions' => [[
            'id' => 'coolify',
            'label' => '@coolify',
            'handles' => ['linkedin_urn' => str_repeat('a', 256)],
        ]],
    ])->assertInvalid(['mentions.0.handles.linkedin_urn']);
});

test('PUT /posts/{post} accepts the real buildPutBody payload with no base_text', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $created = test()->postJson('/posts', [
        'destination' => ['kind' => 'all'],
        'segments' => ['a'],
        'mentions' => [],
    ])->json('post');

    // Mirrors composer-state.ts buildPutBody(): no base_text key.
    $response = test()->putJson("/posts/{$created['id']}", [
        'segments' => ['edited'],
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $accounts[0]->id, 'auto_split' => true, 'content_override' => null]],
        'media_ids' => [],
        'mentions' => [],
        'expected_updated_at' => $created['updated_at'],
    ]);

    $response->assertOk()
        ->assertJsonPath('post.segments', ['edited'])
        ->assertJsonPath('post.base_text', 'edited');
});

test('PUT with a stale expected_updated_at returns 409 with the latest view', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $created = test()->postJson('/posts', ['base_text' => 'a', 'segments' => ['a'], 'destination' => ['kind' => 'all']])->json('post');

    test()->putJson("/posts/{$created['id']}", [
        'base_text' => 'edited',
        'segments' => ['edited'],
        'destination' => ['kind' => 'all'],
        'expected_updated_at' => '2000-01-01T00:00:00+00:00',
    ])->assertStatus(409)->assertJsonPath('post.id', $created['id']);
});

test('a user cannot autosave a post in another workspace', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $foreign = Post::factory()->create(); // different workspace

    test()->putJson("/posts/{$foreign->id}", [
        'base_text' => 'x',
        'segments' => ['x'],
        'destination' => ['kind' => 'all'],
    ])->assertNotFound();
});

test('GET /posts/{post} renders the composer page for an existing post', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $accounts[0]->forceFill(['status' => 'needs_attention'])->save();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'base_text' => 'hello']);

    test()->get("/posts/{$post->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('compose/index')
            ->where('post.id', $post->id)
            ->where('post.base_text', 'hello')
            ->where('accounts.0.status', 'needs_attention'));
});

test('GET /posts/{post} includes saved workspace mentions', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    WorkspaceMention::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => '@saved',
        'handles' => ['x' => '@saved_x'],
    ]);
    WorkspaceMention::factory()->create([
        'name' => '@foreign',
        'handles' => ['x' => '@foreign_x'],
    ]);

    test()->get("/posts/{$post->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('compose/index')
            ->has('savedMentions', 1)
            ->where('savedMentions.0.name', '@saved')
            ->where('savedMentions.0.handles.x', '@saved_x'));
});

test('the old /compose/{post} URL no longer exists', function () {
    [$user, $workspace, $accounts] = actingMember(1);
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);

    test()->get("/compose/{$post->id}")->assertNotFound();
});

test('DELETE /posts/{post} removes the draft and its targets', function () {
    [$user, $workspace, $accounts] = actingMember(2);
    $created = test()->postJson('/posts', ['base_text' => 'a', 'segments' => ['a'], 'destination' => ['kind' => 'all']])->json('post');

    test()->delete("/posts/{$created['id']}")->assertRedirect();

    expect(Post::withoutGlobalScopes()->whereKey($created['id'])->exists())->toBeFalse();
});
