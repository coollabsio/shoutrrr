<?php

use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;

test('update_post edits a draft', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create(['base_text' => 'old']);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'new text',
        'destination' => ['kind' => 'all'],
    ]);

    $response->assertOk()->assertSee('new text');
    expect($post->fresh()->base_text)->toBe('new text');
});

test('update_post reports a stale write conflict', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create(['base_text' => 'old']);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'new',
        'destination' => ['kind' => 'all'],
        'expected_updated_at' => '2000-01-01T00:00:00+00:00', // wrong → stale
    ]);

    $response->assertHasErrors();
});

test('update_post schema advertises threads and per-account targets', function (): void {
    $properties = (new UpdatePostTool)->toArray()['inputSchema']['properties'];

    expect($properties)->toHaveKeys(['segments', 'media_ids', 'mentions', 'targets'])
        ->and($properties['targets']['items']['properties'])->toHaveKeys(['connected_account_id', 'auto_split', 'format', 'content_override']);
});

test('update_post sets a per-account format and thread segments', function (): void {
    [$user, $workspace] = mcpOwner();
    $facebook = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Facebook->value]);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'old']);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => null,
        'segments' => ['one', 'two'],
        'destination' => ['kind' => 'account', 'id' => $facebook->id],
        'targets' => [['connected_account_id' => $facebook->id, 'format' => 'story']],
    ]);

    $response->assertOk();
    expect($post->fresh()->segments)->toBe(['one', 'two'])
        ->and(PostTarget::query()->where('post_id', $post->id)->sole()->format)->toBe(PostFormat::Story);
});

test('update_post rejects a format the platform does not offer', function (): void {
    [$user, $workspace] = mcpOwner();
    $x = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'old']);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'story on X',
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $x->id, 'format' => 'story']],
    ]);

    $response->assertHasErrors(['The story format is not available on x.']);
    expect($post->fresh()->base_text)->toBe('old');
});
