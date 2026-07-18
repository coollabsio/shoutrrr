<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => config(['filesystems.default' => 'public']));

test('it stores an uploaded image as workspace-scoped orphan media', function () {
    config(['filesystems.default' => 's3']);
    Storage::fake('s3');
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    Context::add('workspace_id', $workspace->id);

    $file = UploadedFile::fake()->image('photo.jpg', 1200, 800)->size(400);

    $media = app(MediaStorageService::class)->store($workspace->id, $file);

    expect($media->post_id)->toBeNull()
        ->and($media->workspace_id)->toBe($workspace->id)
        ->and($media->disk)->toBe('s3')
        ->and($media->mime)->toBe('image/jpeg')
        ->and($media->width)->toBe(1200);

    Storage::disk('s3')->assertExists($media->path);
});

test('storeBeautified persists composed + source files and settings', function () {
    config(['filesystems.default' => 's3']);
    Storage::fake('s3');
    $workspace = Workspace::factory()->create();

    $media = app(MediaStorageService::class)->storeBeautified(
        $workspace->id,
        UploadedFile::fake()->image('composed.png', 800, 600),
        UploadedFile::fake()->image('source.png', 1200, 900),
        ['version' => 1, 'padding' => 64],
    );

    Storage::disk('s3')->assertExists($media->path);
    Storage::disk('s3')->assertExists($media->source_path);
    expect($media->edit_settings)->toBe(['version' => 1, 'padding' => 64])
        ->and($media->disk)->toBe('s3')
        ->and($media->source_disk)->toBe('s3')
        ->and($media->workspace_id)->toBe($workspace->id)
        // The returned instance must carry kind (not rely on the DB default), or
        // toView() serializes null and the client can't tell it's an image.
        ->and($media->kind)->toBe('image');
});

test('replaceBeautified swaps the composed file and settings but keeps the source', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();
    $service = app(MediaStorageService::class);

    $media = $service->storeBeautified(
        $workspace->id,
        UploadedFile::fake()->image('c1.png', 400, 400),
        UploadedFile::fake()->image('s.png', 800, 800),
        ['version' => 1, 'padding' => 10],
    );
    $oldPath = $media->path;
    $sourcePath = $media->source_path;

    $updated = $service->replaceBeautified(
        $media,
        UploadedFile::fake()->image('c2.png', 500, 500),
        ['version' => 1, 'padding' => 99],
    );

    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($updated->path);
    Storage::disk('public')->assertExists($sourcePath);
    expect($updated->path)->not->toBe($oldPath)
        ->and($updated->source_path)->toBe($sourcePath)
        ->and($updated->edit_settings)->toBe(['version' => 1, 'padding' => 99]);
});
