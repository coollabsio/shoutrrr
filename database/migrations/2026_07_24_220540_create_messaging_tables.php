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
        Schema::create('conversations', function (Blueprint $table): void {
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

        Schema::create('direct_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('remote_message_id');
            $table->string('direction');
            $table->string('author_remote_id')->nullable();
            $table->text('text')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamp('remote_created_at')->nullable();
            $table->boolean('is_ours')->default(false);
            $table->string('send_status')->nullable();
            $table->string('our_remote_id')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'remote_message_id']);
            $table->index(['conversation_id', 'remote_created_at']);
        });

        // Gives a sent DM's attachments an owner. Media uploaded for a message
        // is created orphaned (`post_id` null), like reply media — harmless for
        // a reply, since the platform owns the copy once posted, but a DM bubble
        // renders our own file indefinitely and `media:prune-uploads` deletes
        // orphaned rows after six hours.
        Schema::table('post_media', function (Blueprint $table): void {
            $table->foreignUuid('direct_message_id')->nullable()->after('post_id')
                ->constrained('direct_messages')->nullOnDelete();
            // Postgres does not auto-index foreign keys, and `DirectMessage::media()`
            // filters on this column for every rendered bubble.
            $table->index('direct_message_id');
        });

        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->timestamp('messaging_rate_limited_until')->nullable()->after('engagement_rate_limited_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->dropColumn('messaging_rate_limited_until');
        });

        Schema::table('post_media', function (Blueprint $table): void {
            // Drop the explicit index before the column, or SQLite refuses to
            // rebuild the table with a dangling index reference.
            $table->dropIndex(['direct_message_id']);
            $table->dropConstrainedForeignId('direct_message_id');
        });

        Schema::dropIfExists('direct_messages');
        Schema::dropIfExists('conversations');
    }
};
