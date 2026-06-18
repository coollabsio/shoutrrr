<?php

declare(strict_types=1);

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

function memberWithChunkPost(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    WorkspaceMembership::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    test()->actingAs($user);
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $postData = test()->postJson('/posts', ['base_text' => '', 'destination' => ['kind' => 'all']])->json('post');
    $post = Post::findOrFail($postData['id']);

    return [$user, $workspace, $post];
}

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * Minimal ISO-BMFF ftyp box (24 bytes) that fileinfo/mime_content_type detects as video/mp4.
 * Structure: box-size (4) + "ftyp" (4) + major-brand "isom" (4) + version (4) + compat "isomiso2" (8)
 */
function mp4Header(): string
{
    return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2";
}

function chunkPayload(array $overrides = []): array
{
    // First chunk starts with a real MP4 ftyp box so the assembled file passes mime_content_type.
    $firstChunk = mp4Header().str_repeat("\x00", 1024 - strlen(mp4Header()));

    return array_merge([
        'upload_id' => '11111111-1111-4111-8111-111111111111',
        'index' => 0,
        'total' => 2,
        'mime' => 'video/mp4',
        'chunk' => UploadedFile::fake()->createWithContent('part', $firstChunk),
    ], $overrides);
}

test('assembles two chunks into one video PostMedia row', function (): void {
    [, , $post] = memberWithChunkPost();

    $first = $this->postJson(route('posts.media.chunk', $post), chunkPayload([
        'index' => 0,
    ]));
    $first->assertOk()->assertJsonPath('received', 1);

    $final = $this->postJson(route('posts.media.chunk', $post), chunkPayload([
        'index' => 1,
        'chunk' => UploadedFile::fake()->createWithContent('part', str_repeat('b', 1024)),
        'duration_seconds' => 12,
        'width' => 1280,
        'height' => 720,
    ]));

    $final->assertCreated()
        ->assertJsonPath('media.kind', 'video')
        ->assertJsonPath('media.duration_seconds', 12);

    $media = PostMedia::firstOrFail();
    expect($media->kind)->toBe('video')
        ->and($media->size_bytes)->toBe(2048)
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue();
});

test('rejects a non-video mime', function (): void {
    [, , $post] = memberWithChunkPost();

    $this->postJson(route('posts.media.chunk', $post), chunkPayload([
        'mime' => 'image/png',
    ]))->assertStatus(422);
});

test('aborts when assembled size exceeds the ceiling', function (): void {
    [, , $post] = memberWithChunkPost();

    // total claims 1 chunk but the chunk is larger than the per-chunk max
    $this->postJson(route('posts.media.chunk', $post), chunkPayload([
        'total' => 1,
        'chunk' => UploadedFile::fake()->create('part', 7000), // 7000 KiB > 6 MiB max rule
    ]))->assertStatus(422);
});
