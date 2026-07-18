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
            if (! Schema::hasColumn('post_targets', 'metrics_unchanged_streak')) {
                $table->unsignedInteger('metrics_unchanged_streak')->default(0)->after('metrics_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            if (Schema::hasColumn('post_targets', 'metrics_unchanged_streak')) {
                $table->dropColumn('metrics_unchanged_streak');
            }
        });
    }
};
