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
