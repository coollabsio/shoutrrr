<?php

use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\CreatePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;

test('create_post creates a draft in the bound workspace', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $response = ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => 'my first draft',
        'destination' => ['kind' => 'all'],
    ]);

    $response->assertOk()->assertSee('my first draft');
    expect(Post::query()->where('workspace_id', $workspace->id)->where('base_text', 'my first draft')->exists())->toBeTrue();
});

test('create_post schema advertises threads, media, and per-account targets', function (): void {
    $properties = (new CreatePostTool)->toArray()['inputSchema']['properties'];

    expect($properties)->toHaveKeys(['segments', 'media_ids', 'mentions', 'targets', 'auto_repost'])
        ->and($properties['targets']['items']['properties'])->toHaveKeys(['connected_account_id', 'auto_split', 'format', 'content_override'])
        ->and($properties['targets']['items']['properties']['format']['enum'])->toBe(['feed', 'reels', 'story']);
});

test('create_post saves a thread with media and per-account target settings', function (): void {
    [$user, $workspace] = mcpOwner();
    $instagram = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram->value]);
    $x = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $media = PostMedia::factory()->for($workspace)->create(['post_id' => null]);

    $response = ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => null,
        'segments' => ['first part', 'second part'],
        'media_ids' => [$media->id],
        'destination' => ['kind' => 'all'],
        'targets' => [
            ['connected_account_id' => $instagram->id, 'format' => 'reels'],
            ['connected_account_id' => $x->id, 'auto_split' => false, 'content_override' => ['text' => 'only on X']],
        ],
    ]);

    $response->assertOk();
    $post = Post::query()->where('workspace_id', $workspace->id)->sole();
    expect($post->segments)->toBe(['first part', 'second part'])
        ->and($media->fresh()->post_id)->toBe($post->id);

    $targets = PostTarget::query()->where('post_id', $post->id)->get()->keyBy('connected_account_id');
    expect($targets[$instagram->id]->format)->toBe(PostFormat::Reels)
        ->and($targets[$x->id]->auto_split)->toBeFalse()
        ->and($targets[$x->id]->content_override)->toBe(['segments' => ['only on X'], 'media_ids' => []]);
});

test('create_post rejects a format the platform does not offer', function (): void {
    [$user, $workspace] = mcpOwner();
    $x = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $response = ShoutrrrServer::actingAs($user)->tool(CreatePostTool::class, [
        'base_text' => 'reel on X',
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $x->id, 'format' => 'reels']],
    ]);

    $response->assertHasErrors(['The reels format is not available on x.']);
    expect(Post::query()->count())->toBe(0);
});
