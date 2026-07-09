<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->boolean('sync_external_posts')->default(false)->after('status');
            $table->timestamp('external_posts_synced_at')->nullable()->after('sync_external_posts');
            $table->index(['platform', 'status', 'sync_external_posts'], 'connected_accounts_external_posts_sync_index');
        });

        Schema::table('post_targets', function (Blueprint $table): void {
            $table->boolean('imported_from_remote')->default(false)->after('remote_ids');
            $table->index(['connected_account_id', 'remote_id'], 'post_targets_external_remote_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropIndex('post_targets_external_remote_lookup_index');
            $table->dropColumn('imported_from_remote');
        });

        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->dropIndex('connected_accounts_external_posts_sync_index');
            $table->dropColumn(['sync_external_posts', 'external_posts_synced_at']);
        });
    }
};
