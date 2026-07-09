<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('cost_weight_microusd')->default(0)->after('quota_weight');
        });

        Schema::table('usage_period_counters', function (Blueprint $table): void {
            $table->unsignedBigInteger('total_cost_microusd')->default(0)->after('total_quota');
        });
    }

    public function down(): void
    {
        Schema::table('usage_events', function (Blueprint $table): void {
            $table->dropColumn('cost_weight_microusd');
        });

        Schema::table('usage_period_counters', function (Blueprint $table): void {
            $table->dropColumn('total_cost_microusd');
        });
    }
};
