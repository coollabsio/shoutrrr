<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record when a publish-now intent pushed its publish jobs, so a replay
     * re-dispatches only when the first dispatch never happened.
     */
    public function up(): void
    {
        Schema::table('agent_intents', function (Blueprint $table): void {
            $table->timestamp('dispatched_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_intents', function (Blueprint $table): void {
            $table->dropColumn('dispatched_at');
        });
    }
};
