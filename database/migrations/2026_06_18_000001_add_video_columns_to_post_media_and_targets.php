<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->string('kind')->default('image')->after('path');
            $table->unsignedInteger('duration_seconds')->nullable()->after('height');
        });

        Schema::table('post_targets', function (Blueprint $table): void {
            $table->json('media_upload_state')->nullable()->after('remote_ids');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'duration_seconds']);
        });

        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropColumn('media_upload_state');
        });
    }
};
