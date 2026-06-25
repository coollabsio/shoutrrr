<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function imageEditMember(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $post = test()->postJson('/posts', ['base_text' => '', 'destination' => ['kind' => 'all']])->json('post');

    return [$user, $workspace, $post];
}

function settingsPayload(): string
{
    return json_encode([
        'version' => 1,
        'background' => ['type' => 'gradient', 'id' => 'sunset', 'angle' => 135, 'stops' => [['color' => '#f00', 'at' => 0], ['color' => '#00f', 'at' => 1]]],
        'padding' => 64,
        'radius' => 12,
        'shadow' => 'medium',
        'aspect' => 'auto',
        'zoom' => 1,
        'tilt' => ['rotateX' => 0, 'rotateY' => 0],
        'crop' => null,
    ]);
}

test('POST /posts/{post}/image-edit stores composed + source and returns edit settings', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = imageEditMember();

    $response = test()->post("/posts/{$post['id']}/image-edit", [
        'composed' => UploadedFile::fake()->image('out.png', 800, 600),
        'source' => UploadedFile::fake()->image('src.png', 1200, 900),
        'settings' => settingsPayload(),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('media.mime', 'image/png')
        ->assertJsonPath('media.edit_settings.padding', 64);
    expect($response->json('media.source_url'))->not->toBeNull();
});

test('PUT /posts/{post}/image-edit/{media} replaces composed + settings', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = imageEditMember();

    $mediaId = test()->post("/posts/{$post['id']}/image-edit", [
        'composed' => UploadedFile::fake()->image('out.png', 800, 600),
        'source' => UploadedFile::fake()->image('src.png', 1200, 900),
        'settings' => settingsPayload(),
    ], ['Accept' => 'application/json'])->json('media.id');

    $newSettings = json_decode(settingsPayload(), true);
    $newSettings['padding'] = 120;

    test()->put("/posts/{$post['id']}/image-edit/{$mediaId}", [
        'composed' => UploadedFile::fake()->image('out2.png', 900, 600),
        'settings' => json_encode($newSettings),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('media.edit_settings.padding', 120);

    expect(PostMedia::findOrFail($mediaId)->source_path)->not->toBeNull();
});

test('it rejects an image upload to a non-editable post', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = imageEditMember();
    Post::findOrFail($post['id'])->forceFill(['status' => 'published'])->save();

    test()->post("/posts/{$post['id']}/image-edit", [
        'composed' => UploadedFile::fake()->image('out.png', 800, 600),
        'source' => UploadedFile::fake()->image('src.png', 1200, 900),
        'settings' => settingsPayload(),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

test('it 404s when updating media from another workspace', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = imageEditMember();
    $foreign = PostMedia::factory()->create();

    test()->put("/posts/{$post['id']}/image-edit/{$foreign->id}", [
        'composed' => UploadedFile::fake()->image('out.png', 800, 600),
        'settings' => settingsPayload(),
    ], ['Accept' => 'application/json'])->assertStatus(404);
});

test('it rejects a non-image composed file', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = imageEditMember();

    test()->post("/posts/{$post['id']}/image-edit", [
        'composed' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        'source' => UploadedFile::fake()->image('src.png', 1200, 900),
        'settings' => settingsPayload(),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});
