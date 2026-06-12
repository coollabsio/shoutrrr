<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\MediaStorageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;

test('it stores an uploaded image as workspace-scoped orphan media', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    Context::add('workspace_id', $workspace->id);

    $file = UploadedFile::fake()->image('photo.jpg', 1200, 800)->size(400);

    $media = app(MediaStorageService::class)->store($workspace->id, $file);

    expect($media->post_id)->toBeNull()
        ->and($media->workspace_id)->toBe($workspace->id)
        ->and($media->mime)->toBe('image/jpeg')
        ->and($media->width)->toBe(1200);

    Storage::disk('public')->assertExists($media->path);
});
