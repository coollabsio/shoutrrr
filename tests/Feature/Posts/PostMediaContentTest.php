<?php

use App\Enums\WorkspaceRole;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Storage;

function mediaContentMember(): array
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

    return [$user, $workspace];
}

test('GET media/{media}/raw streams the composed file same-origin for the workspace member', function () {
    Storage::fake('public');
    [, $workspace] = mediaContentMember();
    Storage::disk('public')->put('media/ws/pic.png', 'COMPOSED-BYTES');

    $media = PostMedia::factory()->create([
        'workspace_id' => $workspace->id,
        'disk' => 'public',
        'path' => 'media/ws/pic.png',
        'mime' => 'image/png',
    ]);

    $response = test()->get(route('media.raw', $media));

    $response->assertOk();
    expect($response->streamedContent())->toBe('COMPOSED-BYTES');
});

test('GET media/{media}/raw streams from the local disk for self-hosters without S3', function () {
    // A self-hosted install with no object storage keeps media on the private
    // `local` disk (storage/app/private). The proxy must stream those bytes just
    // the same — this is the default self-host configuration.
    Storage::fake('local');
    [, $workspace] = mediaContentMember();
    Storage::disk('local')->put('media/ws/local.png', 'LOCAL-DISK-BYTES');

    $media = PostMedia::factory()->create([
        'workspace_id' => $workspace->id,
        'disk' => 'local',
        'path' => 'media/ws/local.png',
        'mime' => 'image/png',
    ]);

    $response = test()->get(route('media.raw', $media));

    $response->assertOk();
    expect($response->streamedContent())->toBe('LOCAL-DISK-BYTES');
});

test('GET media/{media}/raw?variant=source streams the retained source file', function () {
    Storage::fake('public');
    [, $workspace] = mediaContentMember();
    Storage::disk('public')->put('media/ws/composed.png', 'COMPOSED');
    Storage::disk('public')->put('media/ws/source.png', 'SOURCE-BYTES');

    $media = PostMedia::factory()->create([
        'workspace_id' => $workspace->id,
        'disk' => 'public',
        'path' => 'media/ws/composed.png',
        'source_disk' => 'public',
        'source_path' => 'media/ws/source.png',
    ]);

    $response = test()->get(route('media.raw', ['media' => $media, 'variant' => 'source']));

    $response->assertOk();
    expect($response->streamedContent())->toBe('SOURCE-BYTES');
});

test('GET media/{media}/raw?variant=source 404s when no source is retained', function () {
    Storage::fake('public');
    [, $workspace] = mediaContentMember();
    Storage::disk('public')->put('media/ws/pic.png', 'COMPOSED');

    $media = PostMedia::factory()->create([
        'workspace_id' => $workspace->id,
        'disk' => 'public',
        'path' => 'media/ws/pic.png',
    ]);

    test()->get(route('media.raw', ['media' => $media, 'variant' => 'source']))->assertNotFound();
});

test('GET media/{media}/raw 404s for media in another workspace (no IDOR)', function () {
    Storage::fake('public');
    mediaContentMember();
    Storage::disk('public')->put('media/other/pic.png', 'SECRET');

    $otherWorkspace = Workspace::factory()->create();
    $media = PostMedia::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'disk' => 'public',
        'path' => 'media/other/pic.png',
    ]);

    test()->get(route('media.raw', $media))->assertNotFound();
});

test('GET media/{media}/raw redirects a guest to login', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();
    Storage::disk('public')->put('media/ws/pic.png', 'BYTES');
    $media = PostMedia::factory()->create([
        'workspace_id' => $workspace->id,
        'disk' => 'public',
        'path' => 'media/ws/pic.png',
    ]);

    test()->get(route('media.raw', $media))->assertRedirect(route('login'));
});
