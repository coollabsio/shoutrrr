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

function chunkPayload(array $overrides = []): array
{
    return array_merge([
        'upload_id' => '11111111-1111-4111-8111-111111111111',
        'index' => 0,
        'total' => 2,
        'mime' => 'video/mp4',
        'chunk' => UploadedFile::fake()->createWithContent('part', str_repeat('a', 1024)),
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
