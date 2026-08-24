<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->json('provider_options')->nullable()->after('format');
            $table->json('remote_metadata')->nullable()->after('provider_options');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropColumn(['provider_options', 'remote_metadata']);
        });
    }
};
