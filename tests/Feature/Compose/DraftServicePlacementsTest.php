<?php

use App\Dto\Post\DraftData;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\DraftService;
use Illuminate\Support\Facades\Context;

test('saving a draft writes placement rows and target provenance', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]);

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['hello']);

    $m1 = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => null]);

    $data = DraftData::fromArray([
        'base_text' => 'hello',
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $account->id, 'auto_split' => true]],
        'media_ids' => [$m1->id],
        'placements' => [
            ['media_id' => $m1->id, 'segment_ref' => 'b1', 'position' => 0],
        ],
        'segment_breaks' => ['b1'],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);

    $updated = app(DraftService::class)->updateDraft($post, $data);

    $target = $updated->targets->firstWhere('connected_account_id', $account->id);
    $target->load('placements');

    expect($target->placements)->toHaveCount(1)
        ->and($target->placements->first()->segment_ref)->toBe('b1')
        ->and($target->placements->first()->post_media_id)->toBe($m1->id)
        ->and($target->segment_breaks)->toBe(['b1'])
        ->and($target->section_sources)->not->toBeNull();
});

test('a partial update that omits placements and segment breaks preserves them', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]);

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['hello']);

    $m1 = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => null]);

    // First save carries the full placement/break state.
    $full = DraftData::fromArray([
        'base_text' => 'hello',
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $account->id, 'auto_split' => true]],
        'media_ids' => [$m1->id],
        'placements' => [
            ['media_id' => $m1->id, 'segment_ref' => 'b1', 'position' => 0],
        ],
        'segment_breaks' => ['b1'],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);
    $post = app(DraftService::class)->updateDraft($post, $full);

    // A later text-only edit (e.g. via MCP) that omits placements/segment_breaks
    // must not collapse the per-thread media back onto section 0.
    $partial = DraftData::fromArray([
        'base_text' => 'hello world',
        'segments' => ['hello world'],
        'destination' => ['kind' => 'all'],
        'media_ids' => [$m1->id],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);
    $updated = app(DraftService::class)->updateDraft($post, $partial);

    $target = $updated->targets->firstWhere('connected_account_id', $account->id);
    $target->load('placements');

    expect($target->placements)->toHaveCount(1)
        ->and($target->placements->first()->segment_ref)->toBe('b1')
        ->and($target->placements->first()->post_media_id)->toBe($m1->id)
        ->and($target->segment_breaks)->toBe(['b1']);
});

test('a partial update that omits media_ids preserves attached media and placements', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]);

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['hello']);

    $m1 = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => null]);

    // First save attaches media and carries the full placement/break state.
    $full = DraftData::fromArray([
        'base_text' => 'hello',
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $account->id, 'auto_split' => true]],
        'media_ids' => [$m1->id],
        'placements' => [
            ['media_id' => $m1->id, 'segment_ref' => 'b1', 'position' => 0],
        ],
        'segment_breaks' => ['b1'],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);
    $post = app(DraftService::class)->updateDraft($post, $full);

    // An MCP/API partial update that PUTs only segments + destination (no
    // media_ids key at all) must not detach the post's existing media.
    $partial = DraftData::fromArray([
        'segments' => ['hello world'],
        'destination' => ['kind' => 'all'],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);
    $updated = app(DraftService::class)->updateDraft($post, $partial);

    $target = $updated->targets->firstWhere('connected_account_id', $account->id);
    $target->load('placements');

    expect($m1->fresh()->post_id)->toBe($post->id)
        ->and($target->placements)->toHaveCount(1)
        ->and($target->placements->first()->post_media_id)->toBe($m1->id);
});

test('explicitly sending an empty media_ids array still detaches all media', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]);

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['hello']);

    $m1 = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => null]);

    $full = DraftData::fromArray([
        'base_text' => 'hello',
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $account->id, 'auto_split' => true]],
        'media_ids' => [$m1->id],
        'placements' => [
            ['media_id' => $m1->id, 'segment_ref' => 'b1', 'position' => 0],
        ],
        'segment_breaks' => ['b1'],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);
    $post = app(DraftService::class)->updateDraft($post, $full);

    // An explicit empty media_ids array is the client's way of intentionally
    // clearing all media — that must still detach everything.
    $clearing = DraftData::fromArray([
        'segments' => ['hello world'],
        'destination' => ['kind' => 'all'],
        'media_ids' => [],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);
    $updated = app(DraftService::class)->updateDraft($post, $clearing);

    expect($m1->fresh()->post_id)->toBeNull();
    expect($updated->media)->toHaveCount(0);
});

test('an update with diverged per-account placements writes distinct placement rows per target', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $accountA = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]);
    $accountB = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn->value,
    ]);

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['hello']);

    $m1 = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => null]);
    $m2 = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => null]);

    $data = DraftData::fromArray([
        'base_text' => 'hello',
        'destination' => ['kind' => 'all'],
        'targets' => [
            [
                'connected_account_id' => $accountA->id,
                'auto_split' => true,
                'segment_breaks' => ['b1'],
                'placements' => [
                    ['media_id' => $m1->id, 'segment_ref' => 'b1', 'position' => 0],
                    ['media_id' => $m2->id, 'segment_ref' => 'b1', 'position' => 1],
                ],
            ],
            [
                'connected_account_id' => $accountB->id,
                'auto_split' => true,
                'segment_breaks' => ['b1'],
                'placements' => [
                    ['media_id' => $m1->id, 'segment_ref' => '__head__', 'position' => 0],
                    ['media_id' => $m2->id, 'segment_ref' => 'b1', 'position' => 0],
                ],
            ],
        ],
        'media_ids' => [$m1->id, $m2->id],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);

    $updated = app(DraftService::class)->updateDraft($post, $data);

    $targetA = $updated->targets->firstWhere('connected_account_id', $accountA->id);
    $targetB = $updated->targets->firstWhere('connected_account_id', $accountB->id);
    $targetA->load('placements');
    $targetB->load('placements');

    $placementsA = $targetA->placements->keyBy('post_media_id');
    $placementsB = $targetB->placements->keyBy('post_media_id');

    expect($targetA->placements)->toHaveCount(2)
        ->and($placementsA[$m1->id]->segment_ref)->toBe('b1')
        ->and($placementsA[$m2->id]->segment_ref)->toBe('b1');

    expect($targetB->placements)->toHaveCount(2)
        ->and($placementsB[$m1->id]->segment_ref)->toBe('__head__')
        ->and($placementsB[$m2->id]->segment_ref)->toBe('b1');

    // The two targets genuinely diverge: m1 lands in a different segment per account.
    expect($placementsA[$m1->id]->segment_ref)->not->toBe($placementsB[$m1->id]->segment_ref);
});

test('creating a draft persists segment breaks on its targets', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    $account = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]);

    $data = DraftData::fromArray([
        'segments' => ['test', ''],
        'destination' => ['kind' => 'all'],
        'segment_breaks' => ['bk_1'],
    ]);

    $post = app(DraftService::class)->createDraft(
        $workspace->id,
        $user,
        ['kind' => 'all'],
        ['test', ''],
        [],
        null,
        $data,
    );

    $target = $post->targets->firstWhere('connected_account_id', $account->id);

    expect($target->segment_breaks)->toBe(['bk_1']);
});
