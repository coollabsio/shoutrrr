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
            $table->string('source_disk')->nullable()->after('path');
            $table->string('source_path')->nullable()->after('source_disk');
            $table->json('edit_settings')->nullable()->after('source_path');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table): void {
            $table->dropColumn(['source_disk', 'source_path', 'edit_settings']);
        });
    }
};
