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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function memberWithVideoPost(): array
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

// ---------------------------------------------------------------------------
// url endpoint (presigned upload URL)
// ---------------------------------------------------------------------------

test('url endpoint returns a key under the workspace tmp prefix', function (): void {
    // NOTE: Storage::fake() on a local disk does not support temporaryUploadUrl
    // (it throws "This driver does not support creating temporary upload URLs").
    // The test uses Mockery to stub the disk method, asserting the controller
    // wiring without invoking the real presigned-URL implementation.
    $disk = config('media.disk');
    $fakeDisk = Storage::fake($disk);

    [, $workspace, $post] = memberWithVideoPost();

    Storage::shouldReceive('disk')
        ->with($disk)
        ->andReturnUsing(function () use ($fakeDisk) {
            $mock = Mockery::mock($fakeDisk);
            $mock->shouldReceive('temporaryUploadUrl')
                ->once()
                ->andReturn(['url' => 'https://s3.example.com/presigned', 'headers' => ['x-amz-acl' => 'private']]);

            return $mock;
        });

    $response = test()->postJson(route('posts.media.video-url', $post), [
        'content_type' => 'video/mp4',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['key', 'url', 'headers'])
        ->assertJsonPath('url', 'https://s3.example.com/presigned');

    // Key must be scoped to this workspace's tmp prefix.
    expect($response->json('key'))->toStartWith('tmp/media/'.$workspace->id.'/');
    expect($response->json('key'))->toEndWith('.mp4');
});

test('url endpoint rejects non-mp4 content_type', function (): void {
    Storage::fake(config('media.disk'));
    [, , $post] = memberWithVideoPost();

    test()->postJson(route('posts.media.video-url', $post), [
        'content_type' => 'image/png',
    ])->assertStatus(422);
});

test('url endpoint requires authentication', function (): void {
    $workspace = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);

    test()->postJson(route('posts.media.video-url', $post), [
        'content_type' => 'video/mp4',
    ])->assertStatus(401);
});

// ---------------------------------------------------------------------------
// store endpoint (confirm upload)
// ---------------------------------------------------------------------------

test('store endpoint moves the tmp object, creates a PostMedia row, and returns the media descriptor', function (): void {
    $disk = config('media.disk');
    Storage::fake($disk);
    [, $workspace, $post] = memberWithVideoPost();

    $uuid = (string) Str::uuid();
    $key = 'tmp/media/'.$workspace->id.'/'.$uuid.'.mp4';
    Storage::disk($disk)->put($key, str_repeat('x', 1024));

    $response = test()->postJson(route('posts.media.video', $post), [
        'key' => $key,
        'duration_seconds' => 30,
        'width' => 1920,
        'height' => 1080,
    ]);

    $response->assertCreated()
        ->assertJsonPath('media.kind', 'video')
        ->assertJsonPath('media.mime', 'video/mp4');

    $media = PostMedia::firstOrFail();
    expect($media->kind)->toBe('video')
        ->and($media->disk)->toBe($disk)
        ->and($media->duration_seconds)->toBe(30)
        ->and($media->width)->toBe(1920)
        ->and($media->height)->toBe(1080)
        ->and(str_starts_with($media->path, 'media/'.$workspace->id.'/'))->toBeTrue();

    // Tmp object must be gone (moved to permanent path).
    expect(Storage::disk($disk)->exists($key))->toBeFalse();
    // Permanent object must exist.
    expect(Storage::disk($disk)->exists($media->path))->toBeTrue();
});

test('store endpoint rejects a key outside the post workspace tmp prefix', function (): void {
    $disk = config('media.disk');
    Storage::fake($disk);
    [, , $post] = memberWithVideoPost();

    // Key under a different workspace — should be rejected regardless.
    $otherWorkspaceId = (string) Str::uuid();
    $evilKey = 'tmp/media/'.$otherWorkspaceId.'/'.Str::uuid().'.mp4';

    test()->postJson(route('posts.media.video', $post), [
        'key' => $evilKey,
        'duration_seconds' => 5,
        'width' => 640,
        'height' => 480,
    ])->assertStatus(422);

    expect(PostMedia::count())->toBe(0);
});

test('store endpoint rejects a key that bypasses the tmp prefix entirely', function (): void {
    $disk = config('media.disk');
    Storage::fake($disk);
    [, $workspace, $post] = memberWithVideoPost();

    // Put a file at a non-tmp path and try to finalize it.
    $permanentKey = 'media/'.$workspace->id.'/'.Str::uuid().'.mp4';
    Storage::disk($disk)->put($permanentKey, str_repeat('x', 512));

    test()->postJson(route('posts.media.video', $post), [
        'key' => $permanentKey,
        'duration_seconds' => 5,
        'width' => 640,
        'height' => 480,
    ])->assertStatus(422);

    expect(PostMedia::count())->toBe(0);
});

test('store endpoint rejects a key with path traversal', function (): void {
    $disk = config('media.disk');
    Storage::fake($disk);
    [, $workspace, $post] = memberWithVideoPost();

    $traversalKey = 'tmp/media/'.$workspace->id.'/../other-workspace/'.Str::uuid().'.mp4';

    test()->postJson(route('posts.media.video', $post), [
        'key' => $traversalKey,
        'duration_seconds' => 5,
        'width' => 640,
        'height' => 480,
    ])->assertStatus(422);

    expect(PostMedia::count())->toBe(0);
});

test('store endpoint returns 422 when the upload object is missing', function (): void {
    $disk = config('media.disk');
    Storage::fake($disk);
    [, $workspace, $post] = memberWithVideoPost();

    $key = 'tmp/media/'.$workspace->id.'/'.Str::uuid().'.mp4';
    // Do NOT put any file — simulates a client that never completed the upload.

    test()->postJson(route('posts.media.video', $post), [
        'key' => $key,
        'duration_seconds' => 5,
        'width' => 640,
        'height' => 480,
    ])->assertStatus(422);

    expect(PostMedia::count())->toBe(0);
});
