<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('account_set_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_set_id')->constrained('account_sets')->cascadeOnDelete();
            $table->foreignUuid('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['account_set_id', 'connected_account_id']);
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('account_set_id')->nullable()->constrained('account_sets')->nullOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('base_text')->default('');
            $table->string('status')->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('post_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();
            $table->string('platform');
            $table->json('sections')->default('[]');
            $table->json('content_override')->nullable();
            $table->boolean('auto_split')->default(true);
            $table->string('status')->default('pending');
            $table->string('remote_id')->nullable();
            $table->json('remote_ids')->nullable();
            $table->string('error_kind')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'connected_account_id']);
        });

        Schema::create('post_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('post_id')->nullable()->constrained('posts')->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('post_target_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_target_id')->constrained('post_targets')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('status');
            $table->string('error_kind')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['post_target_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_target_attempts');
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('post_targets');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('account_set_members');
        Schema::dropIfExists('account_sets');
    }
};
