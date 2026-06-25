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
