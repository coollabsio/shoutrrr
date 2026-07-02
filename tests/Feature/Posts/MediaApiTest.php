<?php

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function memberWithDraft(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $post = test()->postJson('/posts', ['base_text' => '', 'segments' => [''], 'destination' => ['kind' => 'all']])->json('post');

    return [$user, $workspace, $post];
}

test('POST /posts/{post}/media uploads and returns the media descriptor', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = memberWithDraft();

    // Multipart upload with an explicit JSON Accept header so validation
    // failures return 422 JSON rather than a redirect (postJson drops the
    // Accept header when a file is present).
    test()->post("/posts/{$post['id']}/media", [
        'file' => UploadedFile::fake()->image('p.jpg', 800, 600)->size(300),
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('media.mime', 'image/jpeg')
        ->assertJsonPath('media.kind', 'image');
});

test('it rejects a non-image upload', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = memberWithDraft();

    test()->post("/posts/{$post['id']}/media", [
        'file' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

test('it rejects an image whose resolution exceeds the pixel ceiling', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = memberWithDraft();

    // Lower the ceiling so a normal fake image trips it without allocating a
    // genuine 50-megapixel canvas; the rule reads the header only.
    config(['media.max_image_pixels' => 100]);

    test()->post("/posts/{$post['id']}/media", [
        'file' => UploadedFile::fake()->image('huge.jpg', 800, 600)->size(300),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

test('it rejects an upload to a post that is no longer editable', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = memberWithDraft();
    Post::findOrFail($post['id'])->forceFill(['status' => 'published'])->save();

    test()->post("/posts/{$post['id']}/media", [
        'file' => UploadedFile::fake()->image('p.jpg', 800, 600)->size(300),
    ], ['Accept' => 'application/json'])->assertStatus(422);
});

test('PATCH /posts/{post}/media/{media}/alt updates alt text', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = memberWithDraft();
    $media = test()->post("/posts/{$post['id']}/media", [
        'file' => UploadedFile::fake()->image('p.jpg', 800, 600)->size(300),
    ], ['Accept' => 'application/json'])->json('media');

    test()->patchJson("/posts/{$post['id']}/media/{$media['id']}/alt", ['alt_text' => 'a red bicycle'])
        ->assertOk()
        ->assertJsonPath('media.alt_text', 'a red bicycle');
});

test('it rejects an alt-text update on a post that is no longer editable', function () {
    Storage::fake('public');
    [$user, $workspace, $post] = memberWithDraft();
    $media = test()->post("/posts/{$post['id']}/media", [
        'file' => UploadedFile::fake()->image('p.jpg', 800, 600)->size(300),
    ], ['Accept' => 'application/json'])->json('media');
    Post::findOrFail($post['id'])->forceFill(['status' => 'published'])->save();

    test()->patchJson("/posts/{$post['id']}/media/{$media['id']}/alt", ['alt_text' => 'too late'])
        ->assertStatus(422);
});
