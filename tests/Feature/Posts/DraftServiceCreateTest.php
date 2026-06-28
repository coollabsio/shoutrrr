<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\AccountSet;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\DraftService;
use App\Support\PostView;
use Illuminate\Support\Facades\Context;

function workspaceWithAccounts(int $count = 2): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $accounts = collect(range(1, $count))->map(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]));

    return [$user, $workspace, $accounts];
}

test('createDraft with the all destination seeds one target per active account', function () {
    [$user, $workspace, $accounts] = workspaceWithAccounts(3);

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['hello']);

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->author_id)->toBe($user->id)
        ->and($post->account_set_id)->toBeNull()
        ->and($post->targets()->count())->toBe(3)
        ->and($post->targets->pluck('connected_account_id')->sort()->values()->all())
        ->toEqual($accounts->pluck('id')->sort()->values()->all());
});

test('createDraft with a single-account destination seeds exactly one target', function () {
    [$user, $workspace, $accounts] = workspaceWithAccounts(3);
    $only = $accounts->first();

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'account', 'id' => $only->id], ['']);

    expect($post->targets()->count())->toBe(1)
        ->and($post->targets->first()->connected_account_id)->toBe($only->id);
});

test('createDraft orders the workspace default account first for all destinations', function () {
    [$user, $workspace, $accounts] = workspaceWithAccounts(3);
    $default = $accounts[2];
    $workspace->forceFill(['default_connected_account_id' => $default->id])->save();

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['hello']);

    $view = PostView::make($post->fresh(['targets.account', 'media']));

    expect($view['targets'][0]['connected_account_id'])->toBe($default->id);
});

test('createDraft orders the workspace default account first inside sets', function () {
    [$user, $workspace, $accounts] = workspaceWithAccounts(3);
    $default = $accounts[1];
    $workspace->forceFill(['default_connected_account_id' => $default->id])->save();
    $set = AccountSet::factory()->create(['workspace_id' => $workspace->id]);
    $set->accounts()->attach([$accounts[0]->id, $default->id]);

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'set', 'id' => $set->id], ['hello']);

    $view = PostView::make($post->fresh(['targets.account', 'media']));

    expect($view['targets'][0]['connected_account_id'])->toBe($default->id);
});

test('createDraft keeps long X text together for premium accounts', function () {
    [$user, $workspace, $accounts] = workspaceWithAccounts(1);
    $account = $accounts->first();
    $account->forceFill([
        'capabilities' => [
            'x_premium' => true,
            'max_text_length' => 25_000,
            'verified_type' => 'blue',
        ],
    ])->save();

    $text = str_repeat('a', 400);
    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'account', 'id' => $account->id], [$text]);

    expect($post->targets()->first()->sections)->toBe([$text]);
});

test('createDraft with a set destination snapshots that set membership', function () {
    [$user, $workspace, $accounts] = workspaceWithAccounts(3);
    $set = AccountSet::factory()->create(['workspace_id' => $workspace->id]);
    $set->accounts()->attach([$accounts[0]->id, $accounts[1]->id]);

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'set', 'id' => $set->id], ['']);

    expect($post->account_set_id)->toBe($set->id)
        ->and($post->targets()->count())->toBe(2);
});

test('createDraft with a custom accounts destination seeds the selected targets', function () {
    [$user, $workspace, $accounts] = workspaceWithAccounts(3);

    $post = app(DraftService::class)->createDraft($workspace->id, $user, [
        'kind' => 'accounts',
        'ids' => [$accounts[0]->id, $accounts[2]->id],
    ], ['']);

    expect($post->account_set_id)->toBeNull()
        ->and($post->targets()->count())->toBe(2)
        ->and($post->targets->pluck('connected_account_id')->sort()->values()->all())
        ->toEqual(collect([$accounts[0]->id, $accounts[2]->id])->sort()->values()->all());
});
