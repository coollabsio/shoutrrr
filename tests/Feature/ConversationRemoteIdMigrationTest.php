<?php

use App\Models\PostTarget;
use App\Models\PostTargetReply;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

test('conversation remote ids are backfilled in batched updates', function () {
    $target = PostTarget::factory()->published()->create([
        'remote_id' => 'at://target',
    ]);

    PostTargetReply::factory()->for($target, 'target')->create([
        'remote_reply_id' => 'at://root',
        'parent_remote_id' => 'at://target',
        'remote_created_at' => now()->subMinutes(4),
    ]);

    PostTargetReply::factory()->for($target, 'target')->create([
        'remote_reply_id' => 'at://child',
        'parent_remote_id' => 'at://root',
        'remote_created_at' => now()->subMinutes(3),
    ]);

    PostTargetReply::factory()->for($target, 'target')->create([
        'remote_reply_id' => 'at://grandchild',
        'parent_remote_id' => 'at://child',
        'remote_created_at' => now()->subMinutes(2),
    ]);

    PostTargetReply::factory()->for($target, 'target')->create([
        'remote_reply_id' => 'at://sibling',
        'parent_remote_id' => 'at://target',
        'remote_created_at' => now()->subMinute(),
    ]);

    $migration = include database_path('migrations/2026_07_03_122801_add_conversation_remote_id_to_post_target_replies_table.php');
    $migration->down();

    $postTargetReplyUpdates = 0;

    DB::listen(function (QueryExecuted $query) use (&$postTargetReplyUpdates): void {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'update "post_target_replies"') || str_starts_with($sql, 'update `post_target_replies`')) {
            $postTargetReplyUpdates++;
        }
    });

    $migration->up();

    $conversationRemoteIds = DB::table('post_target_replies')
        ->pluck('conversation_remote_id', 'remote_reply_id')
        ->all();

    expect($conversationRemoteIds)->toMatchArray([
        'at://root' => 'at://root',
        'at://child' => 'at://root',
        'at://grandchild' => 'at://root',
        'at://sibling' => 'at://sibling',
    ]);

    expect($postTargetReplyUpdates)->toBe(1);
});
