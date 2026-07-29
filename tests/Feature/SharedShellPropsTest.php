<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
    WorkspaceMembership::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'role' => WorkspaceRole::Member,
    ]);
    $this->user->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    Context::add('workspace_id', $this->workspace->id);
});

test('shell props expose accounts, sets, and limits on every page', function () {
    ConnectedAccount::factory()->for($this->workspace)->needsAttention()->create();
    AccountSet::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('shell.accounts', 1)
            ->where('shell.accounts.0.status', 'needs_attention')
            ->where('shell.accounts.0.max_video_duration_seconds', 140)
            ->where('shell.accounts.0.auto_repost_enabled', false)
            ->has('shell.sets', 1)
            ->has('shell.limits')
        );
});

test('a partial reload of the unread badge skips the rest of the shell', function () {
    ConnectedAccount::factory()->for($this->workspace)->create();
    AccountSet::factory()->for($this->workspace)->create();

    $response = $this->actingAs($this->user)->get(route('dashboard'));

    // The badge poll runs every minute in every open tab, so the account and set
    // queries must not run for it. Shell members are closures, and Inertia
    // filters props against the partial request before resolving them. Listen
    // from here so only the follow-up partial request is captured.
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response->assertInertia(fn ($page) => $page
        ->reloadOnly(['shell.unreadReplies', 'shell.unreadMessages'], fn ($reload) => $reload
            ->missing('shell.accounts')
            ->missing('shell.sets')
            ->missing('shell.limits')
            ->missing('notifications')
        )
    );

    expect($queries)->each->not->toContain('from "connected_accounts"');
    expect($queries)->each->not->toContain('from "account_sets"');
});
