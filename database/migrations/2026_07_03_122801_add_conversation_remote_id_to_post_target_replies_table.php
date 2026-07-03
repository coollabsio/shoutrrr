<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_target_replies', function (Blueprint $table): void {
            $table->string('conversation_remote_id')->nullable()->after('parent_remote_id');
            $table->index(
                ['workspace_id', 'post_target_id', 'conversation_remote_id', 'remote_created_at'],
                'ptr_workspace_target_conversation_created_index',
            );
        });

        DB::table('post_targets')
            ->select(['id', 'remote_id'])
            ->orderBy('id')
            ->chunkById(100, function ($targets): void {
                foreach ($targets as $target) {
                    $replies = DB::table('post_target_replies')
                        ->where('post_target_id', $target->id)
                        ->orderBy('remote_created_at')
                        ->orderBy('created_at')
                        ->get(['id', 'remote_reply_id', 'parent_remote_id', 'conversation_remote_id']);

                    $conversationByRemoteId = [];

                    foreach ($replies as $reply) {
                        $conversationRemoteId = $reply->remote_reply_id;

                        if (
                            $reply->parent_remote_id !== null
                            && $reply->parent_remote_id !== $target->remote_id
                            && isset($conversationByRemoteId[$reply->parent_remote_id])
                        ) {
                            $conversationRemoteId = $conversationByRemoteId[$reply->parent_remote_id];
                        }

                        $conversationByRemoteId[$reply->remote_reply_id] = $conversationRemoteId;

                        DB::table('post_target_replies')
                            ->where('id', $reply->id)
                            ->update(['conversation_remote_id' => $conversationRemoteId]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        Schema::table('post_target_replies', function (Blueprint $table): void {
            $table->dropIndex('ptr_workspace_target_conversation_created_index');
            $table->dropColumn('conversation_remote_id');
        });
    }
};
