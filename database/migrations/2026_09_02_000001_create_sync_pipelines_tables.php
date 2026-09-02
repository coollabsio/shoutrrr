<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_pipelines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('source_connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'source_connected_account_id']);
        });

        Schema::create('sync_pipeline_destinations', function (Blueprint $table): void {
            $table->foreignUuid('sync_pipeline_id')->constrained('sync_pipelines')->cascadeOnDelete();
            $table->foreignUuid('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['sync_pipeline_id', 'connected_account_id']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->string('origin')->default('composer')->after('status');
            $table->foreignUuid('sync_pipeline_id')->nullable()->after('origin')
                ->constrained('sync_pipelines')->nullOnDelete();
            $table->foreignUuid('source_post_id')->nullable()->after('sync_pipeline_id')
                ->constrained('posts')->nullOnDelete();
            $table->boolean('skip_sync')->default(false)->after('source_post_id');

            $table->unique(['source_post_id', 'sync_pipeline_id']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropUnique(['source_post_id', 'sync_pipeline_id']);
            $table->dropConstrainedForeignId('source_post_id');
            $table->dropConstrainedForeignId('sync_pipeline_id');
            $table->dropColumn(['origin', 'skip_sync']);
        });

        Schema::dropIfExists('sync_pipeline_destinations');
        Schema::dropIfExists('sync_pipelines');
    }
};
