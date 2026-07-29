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
        Schema::create('direct_messages', function (Blueprint $table) {
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
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('direct_message_id');
        });

        Schema::dropIfExists('direct_messages');
    }
};
