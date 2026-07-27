<?php

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function attachPayload(array $overrides = []): array
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

function tinyGif(): string
{
    return base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('services.klipy.key', 'test-key');
    // static.klipy.com is used instead of the spec's cdn.klipy.com: the latter is
    // NXDOMAIN in real DNS, and SafeImageFetcher resolves the host for real
    // before Http::fake ever intercepts the request (see GifAttacherTest).
    Http::fake([
        'https://static.klipy.com/*' => Http::response(tinyGif(), 200, ['Content-Type' => 'image/gif']),
        'https://api.klipy.com/*' => Http::response(['result' => true, 'data' => []]),
    ]);
});

test('attaches a gif to a post and returns a media view', function () {
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);

    $this->actingAs($user)
        ->postJson("/posts/{$post->id}/gifs", attachPayload())
        ->assertCreated()
        ->assertJsonPath('media.kind', 'image')
        ->assertJsonPath('media.mime', 'image/gif')
        ->assertJsonPath('media.alt_text', 'Happy dance');
});

test('404s when gifs are not configured', function () {
    config()->set('services.klipy.key', null);
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);

    $this->actingAs($user)
        ->postJson("/posts/{$post->id}/gifs", attachPayload())
        ->assertNotFound();
});

test('rejects a variant url off the klipy cdn with a 422', function () {
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);

    $this->actingAs($user)
        ->postJson("/posts/{$post->id}/gifs", attachPayload([
            'variants' => [['url' => 'https://169.254.169.254/latest/meta-data', 'mime' => 'image/gif', 'width' => 1, 'height' => 1, 'bytes' => 100]],
        ]))
        ->assertStatus(422);
});

test('rejects an unknown catalog with a 422', function () {
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);

    $this->actingAs($user)
        ->postJson("/posts/{$post->id}/gifs", attachPayload(['catalog' => 'memes']))
        ->assertStatus(422);
});

test('cannot attach to another workspace post', function () {
    $user = User::factory()->withWorkspace()->create();
    $foreign = Post::factory()->create();

    $this->actingAs($user)
        ->postJson("/posts/{$foreign->id}/gifs", attachPayload())
        ->assertNotFound();
});

test('attaches a gif to a reply and returns a media view', function () {
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $reply = PostTargetReply::factory()
        ->for(PostTarget::factory()->for($post), 'target')
        ->create(['workspace_id' => $user->current_workspace_id]);

    $this->actingAs($user)
        ->postJson("/engagement/{$reply->id}/gifs", attachPayload())
        ->assertCreated()
        ->assertJsonPath('media.kind', 'image')
        ->assertJsonPath('media.mime', 'image/gif');
});

test('cannot attach to another workspace reply', function () {
    $user = User::factory()->withWorkspace()->create();
    $foreignReply = PostTargetReply::factory()
        ->for(PostTarget::factory()->for(Post::factory()), 'target')
        ->create();

    $this->actingAs($user)
        ->postJson("/engagement/{$foreignReply->id}/gifs", attachPayload())
        ->assertNotFound();
});

test('404s on the reply route when gifs are not configured', function () {
    config()->set('services.klipy.key', null);
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $reply = PostTargetReply::factory()
        ->for(PostTarget::factory()->for($post), 'target')
        ->create(['workspace_id' => $user->current_workspace_id]);

    $this->actingAs($user)
        ->postJson("/engagement/{$reply->id}/gifs", attachPayload())
        ->assertNotFound();
});

test('a client-declared existing clip blocks a second reply attachment with a 422', function () {
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $reply = PostTargetReply::factory()
        ->for(PostTarget::factory()->for($post), 'target')
        ->create(['workspace_id' => $user->current_workspace_id]);
    $existingImage = PostMedia::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'kind' => 'image',
        'mime' => 'image/png',
    ]);

    $this->actingAs($user)
        ->postJson("/engagement/{$reply->id}/gifs", attachPayload([
            'catalog' => 'clip',
            'variants' => [['url' => 'https://static.klipy.com/ok.mp4', 'mime' => 'video/mp4', 'width' => 320, 'height' => 240, 'bytes' => 40000]],
            'duration_seconds' => 5,
            'media_ids' => [$existingImage->id],
        ]))
        ->assertStatus(422);
});

test('a client-declared existing image blocks a gif reply attachment with a 422', function () {
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $reply = PostTargetReply::factory()
        ->for(PostTarget::factory()->for($post), 'target')
        ->create(['workspace_id' => $user->current_workspace_id]);
    $existingImage = PostMedia::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'kind' => 'image',
        'mime' => 'image/png',
    ]);

    $this->actingAs($user)
        ->postJson("/engagement/{$reply->id}/gifs", attachPayload([
            'media_ids' => [$existingImage->id],
        ]))
        ->assertStatus(422);
});

test('attaching a reply gif with no media_ids still succeeds', function () {
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $reply = PostTargetReply::factory()
        ->for(PostTarget::factory()->for($post), 'target')
        ->create(['workspace_id' => $user->current_workspace_id]);

    $this->actingAs($user)
        ->postJson("/engagement/{$reply->id}/gifs", attachPayload())
        ->assertCreated();
});

test('attaching a reply gif with an explicit empty media_ids array still succeeds', function () {
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $reply = PostTargetReply::factory()
        ->for(PostTarget::factory()->for($post), 'target')
        ->create(['workspace_id' => $user->current_workspace_id]);

    $this->actingAs($user)
        ->postJson("/engagement/{$reply->id}/gifs", attachPayload(['media_ids' => []]))
        ->assertCreated();
});

test('ignores media ids from another workspace', function () {
    $user = User::factory()->withWorkspace()->create();
    $post = Post::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $reply = PostTargetReply::factory()
        ->for(PostTarget::factory()->for($post), 'target')
        ->create(['workspace_id' => $user->current_workspace_id]);
    // Belongs to a different workspace. This proves a foreign media id cannot
    // influence the mixing guard (it is dropped rather than causing the 422 a
    // same-workspace image would) — not that any one specific layer of
    // workspace scoping is doing the filtering. In this request, PostMedia's
    // HasWorkspaceScope global scope (Context::get('workspace_id'), populated
    // by WorkspaceMiddleware) already excludes it before the controller's own
    // explicit where('workspace_id', ...) clause ever runs.
    $foreignImage = PostMedia::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'kind' => 'image',
        'mime' => 'image/png',
    ]);

    $this->actingAs($user)
        ->postJson("/engagement/{$reply->id}/gifs", attachPayload([
            'media_ids' => [$foreignImage->id],
        ]))
        ->assertCreated();
});
