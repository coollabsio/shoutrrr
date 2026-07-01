<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_period_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('category');
            // NOT NULL: a UNIQUE index treats NULLs as distinct, which would let
            // platform-less ops create duplicate rows. Platform-less ops use 'none'.
            $table->string('platform');
            $table->string('operation');
            $table->unsignedBigInteger('event_count')->default(0);
            $table->unsignedBigInteger('total_quota')->default(0);
            $table->timestamps();

            $table->unique(
                ['workspace_id', 'period_start', 'category', 'platform', 'operation'],
                'usage_period_counters_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_period_counters');
    }
};
