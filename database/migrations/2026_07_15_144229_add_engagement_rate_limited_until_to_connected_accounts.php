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
    }
};
