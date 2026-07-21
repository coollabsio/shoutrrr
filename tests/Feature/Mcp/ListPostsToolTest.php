<?php

use App\Enums\PostStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\ListPostsTool;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;

test('list_posts returns workspace posts and filters by status', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    Post::factory()->for($workspace)->create(['base_text' => 'a draft', 'status' => PostStatus::Draft->value]);
    Post::factory()->for($workspace)->create(['base_text' => 'a published', 'status' => PostStatus::Published->value]);

    $all = ShoutrrrServer::actingAs($user)->tool(ListPostsTool::class, []);
    $all->assertOk()->assertSee('a draft')->assertSee('a published');

    $draftsOnly = ShoutrrrServer::actingAs($user)->tool(ListPostsTool::class, ['status' => 'draft']);
    $draftsOnly->assertOk()->assertSee('a draft');
});

test('list_posts filters by q as a case-insensitive substring match', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    Post::factory()->for($workspace)->create(['base_text' => 'Launch announcement']);
    Post::factory()->for($workspace)->create(['base_text' => 'weekly recap']);

    $matches = ShoutrrrServer::actingAs($user)->tool(ListPostsTool::class, ['q' => 'LAUNCH']);
    $matches->assertOk()->assertSee('Launch announcement')->assertDontSee('weekly recap');
});
