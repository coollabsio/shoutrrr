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
use Illuminate\Support\Facades\Route;
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
    $postData = test()->postJson('/posts', ['base_text' => '', 'segments' => [''], 'destination' => ['kind' => 'all']])->json('post');
    $post = Post::findOrFail($postData['id']);

    return [$user, $workspace, $post];
}

// Media must live on a publicly-servable disk; pin the default to `public` for these
// tests (a real deployment sets FILESYSTEM_DISK to `public` or `s3`).
beforeEach(fn () => config(['filesystems.default' => 'public']));

// Minimal ISO-BMFF header (a `ftyp` box at offset 4) so the server-side MP4 magic-byte
// check accepts the fixture; pad to a plausible body size.
function mp4Bytes(int $pad = 1024): string
{
    return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2".str_repeat('x', $pad);
}

// ---------------------------------------------------------------------------
// url endpoint (presigned upload URL)
// ---------------------------------------------------------------------------

test('url endpoint returns a key under the workspace tmp prefix', function (): void {
    // NOTE: Storage::fake() on a local disk does not support temporaryUploadUrl
    // (it throws "This driver does not support creating temporary upload URLs").
    // The test uses Mockery to stub the disk method, asserting the controller
    // wiring without invoking the real presigned-URL implementation.
    $disk = config('filesystems.default');
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
    Storage::fake(config('filesystems.default'));
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

test('url endpoint rejects a post that is no longer editable', function (): void {
    Storage::fake(config('filesystems.default'));
    [, , $post] = memberWithVideoPost();
    $post->forceFill(['status' => 'published'])->save();

    test()->postJson(route('posts.media.video-url', $post), [
        'content_type' => 'video/mp4',
    ])->assertStatus(422);
});

test('video upload routes are rate limited', function (): void {
    $middleware = Route::getRoutes()->getByName('posts.media.video-url')->gatherMiddleware();

    expect(collect($middleware)->contains(fn (string $m): bool => str_contains($m, 'throttle')))->toBeTrue();
});

// ---------------------------------------------------------------------------
// store endpoint (confirm upload)
// ---------------------------------------------------------------------------

test('store endpoint moves the tmp object, creates a PostMedia row, and returns the media descriptor', function (): void {
    $disk = config('filesystems.default');
    Storage::fake($disk);
    [, $workspace, $post] = memberWithVideoPost();

    $uuid = (string) Str::uuid();
    $key = 'tmp/media/'.$workspace->id.'/'.$uuid.'.mp4';
    Storage::disk($disk)->put($key, mp4Bytes());

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

test('store endpoint rejects a post that is no longer editable', function (): void {
    $disk = config('filesystems.default');
    Storage::fake($disk);
    [, $workspace, $post] = memberWithVideoPost();
    $post->forceFill(['status' => 'published'])->save();

    $key = 'tmp/media/'.$workspace->id.'/'.Str::uuid().'.mp4';
    Storage::disk($disk)->put($key, str_repeat('x', 1024));

    test()->postJson(route('posts.media.video', $post), [
        'key' => $key,
        'duration_seconds' => 30,
        'width' => 1920,
        'height' => 1080,
    ])->assertStatus(422);

    expect(PostMedia::count())->toBe(0);
});

test('store endpoint rejects content that is not a valid MP4', function (): void {
    $disk = config('filesystems.default');
    Storage::fake($disk);
    [, $workspace, $post] = memberWithVideoPost();

    $key = 'tmp/media/'.$workspace->id.'/'.Str::uuid().'.mp4';
    // Valid key + exists + within size, but the bytes are not an MP4 container.
    Storage::disk($disk)->put($key, str_repeat('x', 1024));

    test()->postJson(route('posts.media.video', $post), [
        'key' => $key,
        'duration_seconds' => 30,
        'width' => 1920,
        'height' => 1080,
    ])->assertStatus(422);

    expect(PostMedia::count())->toBe(0);
    // The rejected upload is cleaned up, not left in tmp.
    expect(Storage::disk($disk)->exists($key))->toBeFalse();
});

test('store endpoint rejects a key outside the post workspace tmp prefix', function (): void {
    $disk = config('filesystems.default');
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
    $disk = config('filesystems.default');
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
    $disk = config('filesystems.default');
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
    $disk = config('filesystems.default');
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

test('store endpoint returns 422 and deletes the tmp object when the file exceeds the size ceiling', function (): void {
    // Storage::fake() cannot cheaply produce a >512 MB file, so we use Mockery to stub
    // the disk object returned by Storage::disk(). The mock reports exists() = true and
    // size() = ceiling + 1, and we assert that delete() is called before the 422 is returned.
    $disk = config('filesystems.default');
    [, $workspace, $post] = memberWithVideoPost();

    $key = 'tmp/media/'.$workspace->id.'/'.Str::uuid().'.mp4';

    $mockDisk = Mockery::mock();
    $mockDisk->shouldReceive('exists')->with($key)->andReturn(true);
    $mockDisk->shouldReceive('size')->with($key)->andReturn(Platform::maxVideoBytesCeiling() + 1);
    $mockDisk->shouldReceive('delete')->with($key)->once();

    Storage::shouldReceive('disk')
        ->with($disk)
        ->andReturn($mockDisk);

    $response = test()->postJson(route('posts.media.video', $post), [
        'key' => $key,
        'duration_seconds' => 30,
        'width' => 1920,
        'height' => 1080,
    ]);

    $response->assertStatus(422);
    expect(PostMedia::count())->toBe(0);
});
