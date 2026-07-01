<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('category');
            $table->string('operation');
            $table->string('platform')->nullable();
            $table->unsignedInteger('quota_weight')->default(1);
            $table->boolean('succeeded')->default(true);
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['platform', 'operation', 'occurred_at']);
            $table->index('occurred_at'); // prune scans by age
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
    }
};
