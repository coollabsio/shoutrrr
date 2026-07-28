<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Enums\WorkspaceRole;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Inertia\Testing\AssertableInertia as Assert;

test('shell exposes unread messages count', function (): void {
    config()->set('messages.enabled', true);

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Owner,
    ]);
    Context::add('workspace_id', $workspace->id);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Bluesky,
        'capabilities' => ['dm_enabled' => true],
    ]);
    Conversation::factory()->for($account, 'account')->create([
        'workspace_id' => $workspace->id,
        'unread_count' => 4,
    ]);

    $this->actingAs($user)->get('/messages')
        ->assertInertia(fn (Assert $p) => $p->where('features.messages', true)->where('shell.unreadMessages', 4));
});
