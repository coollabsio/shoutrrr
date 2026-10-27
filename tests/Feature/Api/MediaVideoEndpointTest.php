<?php

use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Media must live on a publicly-servable disk; pin the default to `public`
// (a real deployment sets FILESYSTEM_DISK to `public` or `s3`).
beforeEach(function (): void {
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
});

/**
 * Minimal ISO-BMFF header (a `ftyp` box at offset 4) so the MP4 sniff accepts it.
 */
function apiMp4Bytes(): string
{
    return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2".str_repeat('x', 1024);
}

test('returns an absolute signed upload url and a key scoped to the api key workspace', function () {
    [, $workspace, $token] = issuedKey();

    $response = $this->withToken($token)->postJson('/api/v1/media/video/upload-url', [
        'content_type' => 'video/mp4',
    ]);

    $response->assertOk()->assertJsonStructure(['key', 'url', 'headers']);

    expect($response->json('key'))->toStartWith('tmp/media/'.$workspace->id.'/')
        ->and($response->json('key'))->toEndWith('.mp4')
        ->and($response->json('url'))->toStartWith('http')
        ->and($response->json('url'))->toContain('uploads/stream/')
        ->and($response->json('url'))->toContain('signature=');
});

test('upload url rejects a non-mp4 content type', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->postJson('/api/v1/media/video/upload-url', [
        'content_type' => 'video/quicktime',
    ])->assertStatus(422);
});

test('registers an uploaded video and returns the media library shape', function () {
    [, $workspace, $token] = issuedKey();
    $key = 'tmp/media/'.$workspace->id.'/'.Str::uuid().'.mp4';
    Storage::disk('public')->put($key, apiMp4Bytes());

    $response = $this->withToken($token)->postJson('/api/v1/media/video', [
        'key' => $key,
        'duration_seconds' => 18,
        'width' => 1080,
        'height' => 1920,
        'alt_text' => 'a reel',
    ]);

    $response->assertCreated()
        ->assertExactJsonStructure(['id', 'mime', 'width', 'height', 'alt_text'])
        ->assertJsonPath('mime', 'video/mp4')
        ->assertJsonPath('width', 1080)
        ->assertJsonPath('height', 1920)
        ->assertJsonPath('alt_text', 'a reel');

    $media = PostMedia::withoutGlobalScopes()->findOrFail($response->json('id'));
    expect($media->workspace_id)->toBe($workspace->id)
        ->and($media->kind)->toBe('video')
        ->and($media->duration_seconds)->toBe(18)
        ->and($media->post_id)->toBeNull()
        ->and(Storage::disk('public')->exists($key))->toBeFalse()
        ->and(Storage::disk('public')->exists($media->path))->toBeTrue();
});

test('the full presigned flow works end to end through the signed url', function () {
    [, , $token] = issuedKey();

    $signed = $this->withToken($token)->postJson('/api/v1/media/video/upload-url', [
        'content_type' => 'video/mp4',
    ])->assertOk();

    $this->call('PUT', $signed->json('url'), [], [], [], ['CONTENT_TYPE' => 'video/mp4'], apiMp4Bytes())
        ->assertSuccessful();

    $this->withToken($token)->postJson('/api/v1/media/video', [
        'key' => $signed->json('key'),
        'duration_seconds' => 12,
        'width' => 1080,
        'height' => 1920,
    ])->assertCreated()->assertJsonPath('mime', 'video/mp4');
});

test('rejects a key under another workspace prefix', function () {
    [, , $token] = issuedKey();
    [, $otherWorkspace] = issuedKey();
    $foreignKey = 'tmp/media/'.$otherWorkspace->id.'/'.Str::uuid().'.mp4';
    Storage::disk('public')->put($foreignKey, apiMp4Bytes());

    $this->withToken($token)->postJson('/api/v1/media/video', [
        'key' => $foreignKey,
        'duration_seconds' => 5,
        'width' => 640,
        'height' => 480,
    ])->assertStatus(422);

    expect(PostMedia::withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk('public')->exists($foreignKey))->toBeTrue();
});

test('rejects an upload that is not an mp4 container and deletes it', function () {
    [, $workspace, $token] = issuedKey();
    $key = 'tmp/media/'.$workspace->id.'/'.Str::uuid().'.mp4';
    Storage::disk('public')->put($key, str_repeat('x', 1024));

    $this->withToken($token)->postJson('/api/v1/media/video', [
        'key' => $key,
        'duration_seconds' => 5,
        'width' => 640,
        'height' => 480,
    ])->assertStatus(422);

    expect(PostMedia::withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk('public')->exists($key))->toBeFalse();
});

test('requires the video dimensions and duration', function () {
    [, $workspace, $token] = issuedKey();

    $this->withToken($token)->postJson('/api/v1/media/video', [
        'key' => 'tmp/media/'.$workspace->id.'/'.Str::uuid().'.mp4',
    ])->assertStatus(422)->assertJsonValidationErrors(['duration_seconds', 'width', 'height']);
});

test('a read-only key cannot request an upload url or register a video', function () {
    [, $workspace, $token] = issuedKey('read');

    $this->withToken($token)->postJson('/api/v1/media/video/upload-url', [
        'content_type' => 'video/mp4',
    ])->assertForbidden();

    $this->withToken($token)->postJson('/api/v1/media/video', [
        'key' => 'tmp/media/'.$workspace->id.'/'.Str::uuid().'.mp4',
        'duration_seconds' => 5,
        'width' => 640,
        'height' => 480,
    ])->assertForbidden();
});

test('video endpoints require a bearer token', function () {
    $this->postJson('/api/v1/media/video/upload-url', ['content_type' => 'video/mp4'])->assertUnauthorized();
    $this->postJson('/api/v1/media/video', [])->assertUnauthorized();
});
