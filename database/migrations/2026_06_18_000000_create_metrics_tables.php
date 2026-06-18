<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('connected_account_id')->constrained('connected_accounts')->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->unsignedBigInteger('followers')->default(0);
            $table->unsignedBigInteger('following')->nullable();
            $table->unsignedBigInteger('posts_count')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['connected_account_id', 'captured_at']);
        });

        Schema::create('post_target_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_target_id')->constrained('post_targets')->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('reposts')->default(0);
            $table->unsignedBigInteger('impressions')->nullable();
            $table->timestamps();

            $table->unique(['post_target_id', 'captured_at']);
        });

        Schema::table('post_targets', function (Blueprint $table): void {
            $table->unsignedBigInteger('likes')->default(0)->after('posted_at');
            $table->unsignedBigInteger('comments')->default(0)->after('likes');
            $table->unsignedBigInteger('reposts')->default(0)->after('comments');
            $table->unsignedBigInteger('impressions')->nullable()->after('reposts');
            $table->timestamp('metrics_captured_at')->nullable()->after('impressions');
            $table->string('metrics_status')->nullable()->after('metrics_captured_at');

            $table->index(['status', 'metrics_captured_at']);
        });

        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->timestamp('metrics_captured_at')->nullable()->after('last_refreshed_at');
            $table->string('metrics_status')->nullable()->after('metrics_captured_at');

            $table->index(['status', 'metrics_captured_at']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->index(['workspace_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'published_at']);
        });

        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->dropIndex(['status', 'metrics_captured_at']);
            $table->dropColumn(['metrics_captured_at', 'metrics_status']);
        });

        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropIndex(['status', 'metrics_captured_at']);
            $table->dropColumn(['likes', 'comments', 'reposts', 'impressions', 'metrics_captured_at', 'metrics_status']);
        });

        Schema::dropIfExists('post_target_metrics');
        Schema::dropIfExists('account_metrics');
    }
};
