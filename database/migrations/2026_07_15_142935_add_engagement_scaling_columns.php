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
        Schema::table('post_targets', function (Blueprint $table): void {
            if (! Schema::hasColumn('post_targets', 'reply_fetch_empty_streak')) {
                $table->unsignedInteger('reply_fetch_empty_streak')->default(0)->after('reply_fetched_at');
            }
        });

        // The reply dispatcher prefilters by (status, reply_fetched_at) at any post
        // age; index it so the per-tick scan stays cheap at fleet scale.
        if (! Schema::hasIndex('post_targets', 'post_targets_status_reply_fetched_at_index')) {
            Schema::table('post_targets', function (Blueprint $table): void {
                $table->index(['status', 'reply_fetched_at'], 'post_targets_status_reply_fetched_at_index');
            });
        }

        Schema::table('connected_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('connected_accounts', 'engagement_rate_limited_until')) {
                $table->timestamp('engagement_rate_limited_until')->nullable()->after('metrics_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table): void {
            if (Schema::hasColumn('connected_accounts', 'engagement_rate_limited_until')) {
                $table->dropColumn('engagement_rate_limited_until');
            }
        });

        if (Schema::hasIndex('post_targets', 'post_targets_status_reply_fetched_at_index')) {
            Schema::table('post_targets', function (Blueprint $table): void {
                $table->dropIndex('post_targets_status_reply_fetched_at_index');
            });
        }

        Schema::table('post_targets', function (Blueprint $table): void {
            if (Schema::hasColumn('post_targets', 'reply_fetch_empty_streak')) {
                $table->dropColumn('reply_fetch_empty_streak');
            }
        });
    }
};
