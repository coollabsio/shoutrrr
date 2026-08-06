<?php

// tests/Feature/Engagement/ReplyImageEditTest.php
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;

function editSettings(): array
{
    return [
        'version' => 1,
        'background' => ['type' => 'gradient', 'id' => 'sunset'],
        'padding' => 4, 'radius' => 8, 'shadow' => 'md', 'aspect' => 'auto',
        'zoom' => 1, 'tilt' => ['x' => 0, 'y' => 0], 'crop' => null,
    ];
}

beforeEach(function (): void {
    Storage::fake('public');
    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);
    $this->reply = PostTargetReply::factory()
        ->for(PostTarget::factory()->for(Post::factory()->create(['workspace_id' => $this->workspace->id])), 'target')
        ->create(['workspace_id' => $this->workspace->id]);
});

test('storing a beautified image creates media on the reply workspace', function () {
    $this->actingAs($this->user)
        ->post(route('engagement.image-edit.store', $this->reply), [
            'composed' => UploadedFile::fake()->image('out.png')->mimeType('image/png'),
            'source' => UploadedFile::fake()->image('in.jpg'),
            'settings' => editSettings(),
        ])
        ->assertCreated();

    expect(PostMedia::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->count())->toBe(1);
});

test('the reply store endpoint accepts a compact composed image', function (string $file, string $mime) {
    // The editor rasterizes to a compressed JPEG/WebP sized to fit the cap; a
    // lossless PNG of a photo would 422, blanking the reply media (#126).
    $this->actingAs($this->user)
        ->post(route('engagement.image-edit.store', $this->reply), [
            'composed' => UploadedFile::fake()->image($file, 800, 600),
            'source' => UploadedFile::fake()->image('in.jpg'),
            'settings' => editSettings(),
        ])
        ->assertCreated()
        ->assertJsonPath('media.mime', $mime);
})->with([
    'jpeg' => ['out.jpg', 'image/jpeg'],
    'webp' => ['out.webp', 'image/webp'],
]);

test('the reply update endpoint rejects editing an animated gif', function () {
    $gif = PostMedia::factory()->create([
        'workspace_id' => $this->workspace->id, 'kind' => 'image', 'mime' => 'image/gif',
    ]);

    $this->actingAs($this->user)
        ->put(route('engagement.image-edit.update', ['reply' => $this->reply, 'media' => $gif->id]), [
            'composed' => UploadedFile::fake()->image('out.webp', 900, 600),
            'settings' => editSettings(),
        ])
        ->assertStatus(422);
});

test('the reply update endpoint accepts a compact composed image', function () {
    $mediaId = $this->actingAs($this->user)
        ->post(route('engagement.image-edit.store', $this->reply), [
            'composed' => UploadedFile::fake()->image('out.png')->mimeType('image/png'),
            'source' => UploadedFile::fake()->image('in.jpg'),
            'settings' => editSettings(),
        ])->json('media.id');

    $this->actingAs($this->user)
        ->put(route('engagement.image-edit.update', ['reply' => $this->reply, 'media' => $mediaId]), [
            'composed' => UploadedFile::fake()->image('out.webp', 900, 600),
            'settings' => editSettings(),
        ])
        ->assertOk()
        ->assertJsonPath('media.mime', 'image/webp');
});
