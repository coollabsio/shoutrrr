<?php

use App\Dto\Post\DraftData;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\PostMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Posts\DraftService;
use Illuminate\Support\Facades\Context;

/**
 * @return array{0: User, 1: Workspace}
 */
function mediaOwnershipSetup(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    Context::add('workspace_id', $workspace->id);

    return [$user, $workspace];
}

test('a new draft cannot take over media attached to another post', function () {
    [$user, $workspace] = mediaOwnershipSetup();
    $drafts = app(DraftService::class);
    $owner = $drafts->createDraft($workspace->id, $user, ['kind' => 'none'], ['owner']);
    $media = PostMedia::factory()->for($workspace)->create(['post_id' => $owner->id]);

    $thief = $drafts->createDraft($workspace->id, $user, ['kind' => 'none'], ['thief'], data: DraftData::fromArray([
        'segments' => ['thief'],
        'destination' => ['kind' => 'none'],
        'media_ids' => [$media->id],
    ]));

    expect($media->fresh()->post_id)->toBe($owner->id)
        ->and($thief->fresh()->media)->toBeEmpty();
});

test('a new draft cannot take over media attached to a direct message', function () {
    [$user, $workspace] = mediaOwnershipSetup();
    $conversation = Conversation::factory()->create(['workspace_id' => $workspace->id]);
    $message = DirectMessage::factory()->for($conversation)->create(['workspace_id' => $workspace->id]);
    $media = PostMedia::factory()->for($workspace)->create(['post_id' => null, 'direct_message_id' => $message->id]);

    $post = app(DraftService::class)->createDraft($workspace->id, $user, ['kind' => 'none'], ['dm'], data: DraftData::fromArray([
        'segments' => ['dm'],
        'destination' => ['kind' => 'none'],
        'media_ids' => [$media->id],
    ]));

    expect($media->fresh()->post_id)->toBeNull()
        ->and($post->fresh()->media)->toBeEmpty();
});

test('updating a draft keeps and reorders media it already owns', function () {
    [$user, $workspace] = mediaOwnershipSetup();
    $first = PostMedia::factory()->for($workspace)->create(['post_id' => null]);
    $second = PostMedia::factory()->for($workspace)->create(['post_id' => null]);
    $drafts = app(DraftService::class);
    $post = $drafts->createDraft($workspace->id, $user, ['kind' => 'none'], ['mine'], data: DraftData::fromArray([
        'segments' => ['mine'],
        'destination' => ['kind' => 'none'],
        'media_ids' => [$first->id, $second->id],
    ]));

    $updated = $drafts->updateDraft($post, DraftData::fromArray([
        'base_text' => 'mine',
        'destination' => ['kind' => 'none'],
        'media_ids' => [$second->id, $first->id],
        'expected_updated_at' => $post->updated_at->toIso8601String(),
    ]));

    expect($updated->media->pluck('id')->all())->toBe([$second->id, $first->id]);
});
