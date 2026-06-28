<?php

use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\DraftService;
use Illuminate\Support\Facades\Context;

test('a draft built from segments splits one section per non-empty segment', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => 'x',
    ]);

    $post = app(DraftService::class)->createDraft(
        $workspace->id,
        $user,
        ['kind' => 'all'],
        ['first post', 'second post'],
    );

    $target = $post->targets->firstWhere('connected_account_id', $account->id);
    expect($post->segments)->toBe(['first post', 'second post'])
        ->and($post->base_text)->toBe("first post\nsecond post")
        ->and($target->sections)->toBe(['first post', 'second post']);
});

test('a literal --- typed inside a segment publishes as text, not a break', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => 'x',
    ]);

    $post = app(DraftService::class)->createDraft(
        $workspace->id,
        $user,
        ['kind' => 'all'],
        ["before\n---\nafter"],
    );

    $target = $post->targets->firstWhere('connected_account_id', $account->id);
    expect($target->sections)->toBe(["before\n---\nafter"]);
});
