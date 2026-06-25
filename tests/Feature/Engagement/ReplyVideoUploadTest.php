<?php

// tests/Feature/Engagement/ReplyVideoUploadTest.php
use App\Enums\WorkspaceRole;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake(config('filesystems.default'));
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

test('presign returns a workspace-scoped mp4 key', function () {
    $res = $this->actingAs($this->user)
        ->postJson(route('engagement.media.video-url', $this->reply), ['content_type' => 'video/mp4'])
        ->assertOk()
        ->json();

    expect($res['key'])->toStartWith('tmp/media/'.$this->workspace->id.'/');
    expect($res['key'])->toEndWith('.mp4');
});

test('confirm rejects a non-mp4 object and accepts a valid one', function () {
    $disk = Storage::disk(config('filesystems.default'));
    $key = 'tmp/media/'.$this->workspace->id.'/'.Str::uuid().'.mp4';

    // Bytes whose offset-4 is NOT "ftyp" → rejected.
    $disk->put($key, 'not-an-mp4-file-at-all');
    $this->actingAs($this->user)->postJson(route('engagement.media.video', $this->reply), [
        'key' => $key, 'duration_seconds' => 5, 'width' => 100, 'height' => 100,
    ])->assertStatus(422);

    // Valid ftyp signature at offset 4 → accepted.
    $disk->put($key, "\x00\x00\x00\x18ftypmp42extra-bytes-here");
    $this->actingAs($this->user)->postJson(route('engagement.media.video', $this->reply), [
        'key' => $key, 'duration_seconds' => 5, 'width' => 100, 'height' => 100,
    ])->assertCreated();

    expect(PostMedia::withoutGlobalScopes()->where('kind', 'video')->count())->toBe(1);
});

test('confirm rejects a key outside the workspace prefix', function () {
    $this->actingAs($this->user)->postJson(route('engagement.media.video', $this->reply), [
        'key' => 'tmp/media/other-ws/'.Str::uuid().'.mp4',
        'duration_seconds' => 5, 'width' => 100, 'height' => 100,
    ])->assertStatus(422);
});
