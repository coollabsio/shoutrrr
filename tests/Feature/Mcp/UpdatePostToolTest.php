<?php

use App\Enums\Platform;
use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\ConnectedAccount;
use App\Models\Post;
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

test('update_post sets the TikTok options of a TikTok target', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'clip']);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'clip',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [[
            'connected_account_id' => $account->id,
            'tiktok' => ['post_mode' => 'direct', 'privacy_level' => 'PUBLIC_TO_EVERYONE', 'allow_comment' => true],
        ]],
    ]);

    $response->assertOk();
    $options = $post->targets()->sole()->tiktok_options;
    expect($options->mode)->toBe(TikTokPostMode::Direct)
        ->and($options->privacyLevel)->toBe(TikTokPrivacyLevel::PublicToEveryone)
        ->and($options->allowComment)->toBeTrue();
});

test('update_post rejects TikTok options TikTok would refuse', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $account = ConnectedAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    $post = Post::factory()->for($workspace)->create(['base_text' => 'clip']);

    $response = ShoutrrrServer::actingAs($user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'base_text' => 'clip',
        'destination' => ['kind' => 'account', 'id' => $account->id],
        'targets' => [[
            'connected_account_id' => $account->id,
            'tiktok' => ['post_mode' => 'direct', 'privacy_level' => 'SELF_ONLY', 'disclose_commercial' => true, 'branded_content' => true],
        ]],
    ]);

    $response->assertHasErrors(['Branded content visibility cannot be set to private.']);
    expect($post->targets()->count())->toBe(0);
});
