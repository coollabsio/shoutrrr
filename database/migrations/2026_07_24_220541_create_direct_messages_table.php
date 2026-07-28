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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('direct_messages');
    }
};
