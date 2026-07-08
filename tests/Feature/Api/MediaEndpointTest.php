<?php

use App\Models\PostMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('uploads an image file to the media library', function () {
    Storage::fake('public');
    [, , $token] = issuedKey();

    $this->withToken($token)->postJson('/api/v1/media', [
        'file' => UploadedFile::fake()->image('pic.png', 200, 200),
        'alt_text' => 'a picture',
    ])
        ->assertCreated()
        ->assertJsonStructure(['id', 'mime', 'width', 'height', 'alt_text']);
});

test('removes media by id', function () {
    [, $workspace, $token] = issuedKey();
    $media = PostMedia::factory()->for($workspace)->create();

    $this->withToken($token)->deleteJson("/api/v1/media/{$media->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(PostMedia::whereKey($media->id)->exists())->toBeFalse();
});

test('a read-only key cannot upload', function () {
    [, , $token] = issuedKey('read');

    $this->withToken($token)->postJson('/api/v1/media', [
        'file' => UploadedFile::fake()->image('pic.png'),
    ])->assertForbidden();
});
