<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->unique()->constrained('workspaces')->cascadeOnDelete();
            $table->string('timezone')->default('UTC');
            $table->timestamps();
        });

        Schema::create('posting_schedule_slots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('posting_schedule_id')->constrained('posting_schedules')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0=Sunday .. 6=Saturday
            $table->unsignedTinyInteger('hour');    // 0..23
            $table->unsignedTinyInteger('minute')->default(0); // 0..59
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['posting_schedule_id', 'weekday', 'hour', 'minute']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_schedule_slots');
        Schema::dropIfExists('posting_schedules');
    }
};
