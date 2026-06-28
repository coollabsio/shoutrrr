<?php

use App\Dto\Post\DraftData;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\DraftService;
use App\Services\Posts\PostStaleWriteException;
use Illuminate\Support\Facades\Context;

function draftSetup(int $count = 2): array
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

test('updateDraft re-splits base text into every target', function () {
    [$user, $workspace, $accounts] = draftSetup(2);
    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['old']);

    $data = DraftData::fromArray([
        'base_text' => 'brand new body',
        'destination' => ['kind' => 'all'],
        'targets' => $accounts->map(fn ($a) => ['connected_account_id' => $a->id, 'auto_split' => true])->all(),
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);

    $updated = app(DraftService::class)->updateDraft($post, $data);

    expect($updated->base_text)->toBe('brand new body')
        ->and($updated->targets->first()->sections)->toBe(['brand new body']);
});

test('updateDraft applies a per-account override and re-splits only that target', function () {
    [$user, $workspace, $accounts] = draftSetup(2);
    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['shared']);
    $first = $accounts->first();

    $data = DraftData::fromArray([
        'base_text' => 'shared',
        'destination' => ['kind' => 'all'],
        'targets' => [
            ['connected_account_id' => $first->id, 'auto_split' => true, 'content_override' => ['text' => 'custom for x']],
            ['connected_account_id' => $accounts[1]->id, 'auto_split' => true],
        ],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]);

    $updated = app(DraftService::class)->updateDraft($post, $data);
    $target = $updated->targets->firstWhere('connected_account_id', $first->id);

    expect($target->sections)->toBe(['custom for x'])
        ->and($target->content_override)->toBe(['segments' => ['custom for x'], 'media_ids' => []]);
});

test('switching destination preserves surviving accounts edits (smart merge)', function () {
    [$user, $workspace, $accounts] = draftSetup(3);
    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['base']);
    $keep = $accounts[0];

    // First, give $keep an override.
    app(DraftService::class)->updateDraft($post, DraftData::fromArray([
        'base_text' => 'base',
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $keep->id, 'content_override' => ['text' => 'kept text']]],
        'expected_updated_at' => $post->fresh()->updated_at->toIso8601String(),
    ]));

    // Now narrow to a single-account destination ($keep).
    $narrowed = app(DraftService::class)->updateDraft($post->fresh(), DraftData::fromArray([
        'base_text' => 'base',
        'destination' => ['kind' => 'account', 'id' => $keep->id],
        'targets' => [['connected_account_id' => $keep->id]],
        'expected_updated_at' => $post->fresh()->updated_at->toIso8601String(),
    ]));

    expect($narrowed->targets)->toHaveCount(1)
        ->and($narrowed->targets->first()->content_override)->toBe(['segments' => ['kept text'], 'media_ids' => []]);
});

test('switching to a custom accounts destination preserves selected account edits', function () {
    [$user, $workspace, $accounts] = draftSetup(3);
    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['base']);
    $keep = $accounts[0];
    $add = $accounts[2];

    app(DraftService::class)->updateDraft($post, DraftData::fromArray([
        'base_text' => 'base',
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $keep->id, 'content_override' => ['text' => 'kept text']]],
        'expected_updated_at' => $post->fresh()->updated_at->toIso8601String(),
    ]));

    $updated = app(DraftService::class)->updateDraft($post->fresh(), DraftData::fromArray([
        'base_text' => 'base',
        'destination' => ['kind' => 'accounts', 'ids' => [$keep->id, $add->id]],
        'targets' => [['connected_account_id' => $keep->id]],
        'expected_updated_at' => $post->fresh()->updated_at->toIso8601String(),
    ]));

    expect($updated->account_set_id)->toBeNull()
        ->and($updated->targets)->toHaveCount(2)
        ->and($updated->targets->firstWhere('connected_account_id', $keep->id)->content_override)->toBe(['segments' => ['kept text'], 'media_ids' => []]);
});

test('updateDraft attaches and orders media', function () {
    [$user, $workspace, $accounts] = draftSetup(1);
    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['']);
    $m1 = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => null]);
    $m2 = PostMedia::factory()->create(['workspace_id' => $workspace->id, 'post_id' => null]);

    $updated = app(DraftService::class)->updateDraft($post, DraftData::fromArray([
        'base_text' => '',
        'destination' => ['kind' => 'all'],
        'media_ids' => [$m2->id, $m1->id],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]));

    expect($updated->media->pluck('id')->all())->toBe([$m2->id, $m1->id])
        ->and($updated->media->first()->position)->toBe(0);
});

test('a stale expected_updated_at throws', function () {
    [$user, $workspace, $accounts] = draftSetup(1);
    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'all'], ['']);

    $stale = DraftData::fromArray([
        'base_text' => 'x',
        'destination' => ['kind' => 'all'],
        'expected_updated_at' => '2000-01-01T00:00:00+00:00',
    ]);

    expect(fn () => app(DraftService::class)->updateDraft($post, $stale))
        ->toThrow(PostStaleWriteException::class);
});

test('updateDraft resolves mention placeholders per target platform before splitting', function () {
    [$user, $workspace, $accounts] = draftSetup(0);
    $x = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]);
    $bluesky = ConnectedAccount::factory()->bluesky()->create([
        'workspace_id' => $workspace->id,
    ]);

    $post = app(DraftService::class)->createDraft(
        $workspace->id,
        $user,
        ['kind' => 'all'],
        ['Hello {{mention:guest}}'],
    );

    $updated = app(DraftService::class)->updateDraft($post, DraftData::fromArray([
        'base_text' => 'Hello {{mention:guest}}',
        'mentions' => [[
            'id' => 'guest',
            'label' => 'Guest',
            'handles' => [
                'x' => '@guest_x',
                'bluesky' => '@guest.bsky.social',
            ],
        ]],
        'destination' => ['kind' => 'accounts', 'ids' => [$x->id, $bluesky->id]],
        'targets' => [
            ['connected_account_id' => $x->id, 'auto_split' => true],
            ['connected_account_id' => $bluesky->id, 'auto_split' => true],
        ],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]));

    expect($updated->base_text)->toBe('Hello {{mention:guest}}')
        ->and($updated->mentions)->toBe([
            [
                'id' => 'guest',
                'label' => 'Guest',
                'handles' => [
                    'x' => '@guest_x',
                    'bluesky' => '@guest.bsky.social',
                ],
            ],
        ])
        ->and($updated->targets->firstWhere('connected_account_id', $x->id)->sections)->toBe(['Hello @guest_x'])
        ->and($updated->targets->firstWhere('connected_account_id', $bluesky->id)->sections)->toBe(['Hello @guest.bsky.social']);
});

test('updateDraft resolves typed at-mention placeholders per target platform before splitting', function () {
    [$user, $workspace, $accounts] = draftSetup(0);
    $x = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X->value,
    ]);
    $linkedin = ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn->value,
    ]);

    $post = app(DraftService::class)->createDraft(
        $workspace->id,
        $user,
        ['kind' => 'all'],
        ['Hello @guest'],
    );

    $updated = app(DraftService::class)->updateDraft($post, DraftData::fromArray([
        'base_text' => 'Hello @guest',
        'mentions' => [[
            'id' => 'guest',
            'label' => '@guest',
            'handles' => [
                'x' => '@guest_x',
                'linkedin' => '@GuestLinkedIn',
            ],
        ]],
        'destination' => ['kind' => 'accounts', 'ids' => [$x->id, $linkedin->id]],
        'targets' => [
            ['connected_account_id' => $x->id, 'auto_split' => true],
            ['connected_account_id' => $linkedin->id, 'auto_split' => true],
        ],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]));

    expect($updated->targets->firstWhere('connected_account_id', $x->id)->sections)->toBe(['Hello @guest_x'])
        ->and($updated->mentions[0]['handles']['linkedin'])->toBe('GuestLinkedIn')
        ->and($updated->targets->firstWhere('connected_account_id', $linkedin->id)->sections)->toBe(['Hello GuestLinkedIn']);
});
