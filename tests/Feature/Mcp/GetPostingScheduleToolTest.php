<?php

use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\GetPostingScheduleTool;
use App\Models\PostingSchedule;
use App\Models\User;
use App\Models\Workspace;

test('get_posting_schedule returns the workspace schedule and slots', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $schedule = PostingSchedule::factory()->for($workspace)->create(['timezone' => 'America/New_York']);
    $schedule->slots()->create(['weekday' => 1, 'hour' => 9, 'minute' => 30, 'position' => 0]);

    $response = ShoutrrrServer::actingAs($user)->tool(GetPostingScheduleTool::class, []);
    $response->assertOk()
        ->assertSee('America/New_York')
        ->assertSee('"minute": 30');
});
