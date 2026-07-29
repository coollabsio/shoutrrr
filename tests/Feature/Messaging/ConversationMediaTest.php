<?php

// tests/Feature/Messaging/ConversationMediaTest.php
use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Helper names are prefixed to avoid colliding with the identically shaped
 * helpers in tests/Feature/Gifs/AttachGifEndpointTest.php — test-file functions
 * are global, so two `attachPayload()`s in one process is a fatal.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function conversationGifPayload(array $overrides = []): array
{
    return array_merge([
        'catalog' => 'gif',
        'slug' => 'happy-dance-991',
        'title' => 'Happy dance',
        'variants' => [
            ['url' => 'https://static.klipy.com/ok.gif', 'mime' => 'image/gif', 'width' => 320, 'height' => 240, 'bytes' => 40000],
        ],
    ], $overrides);
}

function conversationTinyGif(): string
{
    return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}

/**
 * Every attachment endpoint, as [method, route name, payload] — used to prove
 * the platform guard and the workspace scoping hold across all of them at once.
 *
 * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function conversationMediaEndpoints(): array
{
    return [
        ['postJson', 'messages.media.store', ['file' => UploadedFile::fake()->image('pic.jpg')]],
        ['postJson', 'messages.media.video-url', ['content_type' => 'video/mp4']],
        ['postJson', 'messages.media.video', ['key' => 'tmp/media/x/y.mp4', 'duration_seconds' => 5, 'width' => 10, 'height' => 10]],
        ['postJson', 'messages.gifs.store', []],
    ];
}

beforeEach(function (): void {
    config()->set('messages.enabled', true);
    config()->set('services.klipy.key', 'test-key');
    Storage::fake(config('filesystems.default'));
    Storage::fake('public');
    Http::fake([
        'https://static.klipy.com/*' => Http::response(conversationTinyGif(), 200, ['Content-Type' => 'image/gif']),
        'https://api.klipy.com/*' => Http::response(['result' => true, 'data' => []]),
    ]);

    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $this->workspace->id);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);
    $this->conversation = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);
});

test('uploading an image creates orphan media on the conversation workspace', function () {
    $this->actingAs($this->user)
        ->postJson(route('messages.media.store', $this->conversation), [
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

test('updating alt text on conversation media persists it', function () {
    $media = PostMedia::factory()->create(['workspace_id' => $this->workspace->id, 'alt_text' => null]);

    $this->actingAs($this->user)
        ->patchJson(route('messages.media.alt', ['conversation' => $this->conversation, 'media' => $media]), [
            'alt_text' => 'described for screen readers',
        ])
        ->assertOk()
        ->assertJsonPath('media.alt_text', 'described for screen readers');

    expect($media->refresh()->alt_text)->toBe('described for screen readers');
});

test('deleting conversation media removes the row', function () {
    $media = PostMedia::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->deleteJson(route('messages.media.destroy', ['conversation' => $this->conversation, 'media' => $media]))
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(PostMedia::withoutGlobalScopes()->whereKey($media->id)->exists())->toBeFalse();
});

test('presign returns a workspace-scoped mp4 key', function () {
    $res = $this->actingAs($this->user)
        ->postJson(route('messages.media.video-url', $this->conversation), ['content_type' => 'video/mp4'])
        ->assertOk()
        ->json();

    expect($res['key'])->toStartWith('tmp/media/'.$this->workspace->id.'/');
    expect($res['key'])->toEndWith('.mp4');
});

test('confirming a video upload rejects non-mp4 bytes and accepts a real container', function () {
    $disk = Storage::disk(config('filesystems.default'));
    $key = 'tmp/media/'.$this->workspace->id.'/'.Str::uuid().'.mp4';

    $disk->put($key, 'not-an-mp4-file-at-all');
    $this->actingAs($this->user)->postJson(route('messages.media.video', $this->conversation), [
        'key' => $key, 'duration_seconds' => 5, 'width' => 100, 'height' => 100,
    ])->assertStatus(422);

    $disk->put($key, "\x00\x00\x00\x18ftypmp42extra-bytes-here");
    $this->actingAs($this->user)->postJson(route('messages.media.video', $this->conversation), [
        'key' => $key, 'duration_seconds' => 5, 'width' => 100, 'height' => 100,
    ])->assertCreated();

    expect(PostMedia::withoutGlobalScopes()->where('kind', 'video')->count())->toBe(1);
});

test('attaching a gif to a conversation returns a media view', function () {
    $this->actingAs($this->user)
        ->postJson(route('messages.gifs.store', $this->conversation), conversationGifPayload())
        ->assertCreated()
        ->assertJsonPath('media.kind', 'image')
        ->assertJsonPath('media.mime', 'image/gif')
        ->assertJsonPath('media.alt_text', 'Happy dance');
});

test('a client-declared existing image blocks a second conversation attachment with a 422', function () {
    $existingImage = PostMedia::factory()->create([
        'workspace_id' => $this->workspace->id, 'kind' => 'image', 'mime' => 'image/png',
    ]);

    $this->actingAs($this->user)
        ->postJson(route('messages.gifs.store', $this->conversation), conversationGifPayload([
            'media_ids' => [$existingImage->id],
        ]))
        ->assertStatus(422);
});

test('every attachment endpoint 404s on a bluesky conversation', function () {
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id, 'platform' => Platform::Bluesky,
    ]);
    $bluesky = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $this->workspace->id, 'platform' => Platform::Bluesky,
    ]);
    $media = PostMedia::factory()->create(['workspace_id' => $this->workspace->id]);

    foreach (conversationMediaEndpoints() as [$method, $name, $payload]) {
        $this->actingAs($this->user)
            ->{$method}(route($name, $bluesky), $payload)
            ->assertNotFound();
    }

    $this->actingAs($this->user)
        ->patchJson(route('messages.media.alt', ['conversation' => $bluesky, 'media' => $media]), ['alt_text' => 'nope'])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->deleteJson(route('messages.media.destroy', ['conversation' => $bluesky, 'media' => $media]))
        ->assertNotFound();

    expect($media->refresh()->alt_text)->not->toBe('nope');
});

test('every attachment endpoint 404s on another workspace conversation', function () {
    $foreignWorkspace = Workspace::factory()->create();
    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $foreignWorkspace->id, 'platform' => Platform::X,
    ]);
    $foreign = Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $foreignWorkspace->id, 'platform' => Platform::X,
    ]);

    foreach (conversationMediaEndpoints() as [$method, $name, $payload]) {
        $this->actingAs($this->user)
            ->{$method}(route($name, $foreign), $payload)
            ->assertNotFound();
    }
});

test('media belonging to another workspace 404s on alt and delete', function () {
    $foreignMedia = PostMedia::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    $this->actingAs($this->user)
        ->patchJson(route('messages.media.alt', ['conversation' => $this->conversation, 'media' => $foreignMedia]), [
            'alt_text' => 'nope',
        ])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->deleteJson(route('messages.media.destroy', ['conversation' => $this->conversation, 'media' => $foreignMedia]))
        ->assertNotFound();

    expect(PostMedia::withoutGlobalScopes()->whereKey($foreignMedia->id)->exists())->toBeTrue();
});

test('the gif route 404s when gifs are not configured', function () {
    config()->set('services.klipy.key', null);

    $this->actingAs($this->user)
        ->postJson(route('messages.gifs.store', $this->conversation), conversationGifPayload())
        ->assertNotFound();
});
