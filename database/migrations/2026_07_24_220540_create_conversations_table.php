<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connected_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('remote_conversation_id');
            $table->string('counterpart_handle')->nullable();
            $table->string('counterpart_name')->nullable();
            $table->string('counterpart_avatar_url')->nullable();
            $table->string('counterpart_remote_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->text('last_message_preview')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('messaging_window_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['connected_account_id', 'remote_conversation_id']);
            $table->index(['workspace_id', 'archived_at', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
