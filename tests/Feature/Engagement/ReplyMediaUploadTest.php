<?php

// tests/Feature/Engagement/ReplyMediaUploadTest.php
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

test('uploading an image creates orphan media on the reply workspace', function () {
    $this->actingAs($this->user)
        ->postJson(route('engagement.media.store', $this->reply), [
            'file' => UploadedFile::fake()->image('pic.jpg', 200, 200),
            'alt_text' => 'a picture',
        ])
        ->assertCreated()
        ->assertJsonStructure(['media' => ['id', 'url', 'kind']]);

    $media = PostMedia::withoutGlobalScopes()->first();
    expect($media->workspace_id)->toBe($this->workspace->id);
    expect($media->post_id)->toBeNull();
    expect($media->alt_text)->toBe('a picture');
});

test('a foreign-workspace reply 404s', function () {
    $foreign = PostTargetReply::factory()->create(['workspace_id' => 'other-ws']);

    $this->actingAs($this->user)
        ->postJson(route('engagement.media.store', $foreign), [
            'file' => UploadedFile::fake()->image('pic.jpg'),
        ])
        ->assertNotFound();
});
