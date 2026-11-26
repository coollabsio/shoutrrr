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
            $table->timestamp('refresh_token_expires_at')->nullable()->after('token_expires_at');
            $table->timestamp('reconnect_reminded_at')->nullable()->after('refresh_failure_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table): void {
            $table->dropColumn(['refresh_token_expires_at', 'reconnect_reminded_at']);
        });
    }
};
