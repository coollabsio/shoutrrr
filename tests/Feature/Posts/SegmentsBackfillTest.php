<?php

use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\Posts\LegacyManualBreaks;
use Illuminate\Support\Facades\DB;

test('backfill derives segments from legacy --- base_text and cleans base_text', function () {
    $post = Post::factory()->create();
    // Simulate a legacy row: raw base_text with markers, no segments yet.
    DB::table('posts')->where('id', $post->id)->update([
        'base_text' => "first\n---\nsecond",
        'segments' => '[]',
    ]);

    LegacyManualBreaks::backfillExistingPosts();

    $fresh = Post::withoutGlobalScopes()->find($post->id);
    expect($fresh->segments)->toBe(['first', 'second'])
        ->and($fresh->base_text)->toBe("first\nsecond");
});

test('backfill converts a target override text into override segments', function () {
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create(['workspace_id' => $post->workspace_id]);
    $target = PostTarget::factory()->create([
        'post_id' => $post->id,
        'connected_account_id' => $account->id,
        'content_override' => ['text' => "x\n---\ny", 'media_ids' => []],
    ]);

    LegacyManualBreaks::backfillExistingPosts();

    $override = PostTarget::withoutGlobalScopes()->find($target->id)->content_override;
    expect($override)->toBe(['media_ids' => [], 'segments' => ['x', 'y']]);
});
