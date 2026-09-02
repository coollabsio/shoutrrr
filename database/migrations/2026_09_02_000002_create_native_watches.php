<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_account_native_watches', function (Blueprint $table): void {
            $table->foreignUuid('connected_account_id')->primary()->constrained('connected_accounts')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->timestamp('enabled_at');
            $table->string('last_seen_remote_id')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->foreignUuid('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('workspace_id');
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->json('external_media')->nullable()->after('skip_sync');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('external_media');
        });
        Schema::dropIfExists('connected_account_native_watches');
    }
};
