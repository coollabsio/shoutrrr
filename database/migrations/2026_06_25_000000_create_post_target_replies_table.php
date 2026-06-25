<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_target_replies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->foreignUuid('post_target_id')->constrained('post_targets')->cascadeOnDelete();
            $table->string('platform');
            $table->string('remote_reply_id');
            $table->string('remote_cid')->nullable();
            $table->string('parent_remote_id')->nullable();
            $table->string('author_handle');
            $table->string('author_name')->nullable();
            $table->string('author_avatar_url')->nullable();
            $table->text('text');
            $table->timestamp('remote_created_at');
            $table->timestamp('read_at')->nullable();
            $table->string('status')->default('pending');
            $table->string('our_reply_remote_id')->nullable();
            $table->boolean('is_ours')->default(false);
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['post_target_id', 'remote_reply_id']);
            $table->index(['workspace_id', 'read_at']);
            $table->index(['workspace_id', 'platform']);
            $table->index(['workspace_id', 'remote_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_target_replies');
    }
};
