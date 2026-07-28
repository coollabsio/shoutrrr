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
        if (! Schema::hasColumn('connected_accounts', 'messaging_rate_limited_until')) {
            Schema::table('connected_accounts', function (Blueprint $table) {
                $table->timestamp('messaging_rate_limited_until')->nullable()->after('engagement_rate_limited_until');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('connected_accounts', 'messaging_rate_limited_until')) {
            Schema::table('connected_accounts', function (Blueprint $table) {
                $table->dropColumn('messaging_rate_limited_until');
            });
        }
    }
};
