<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->timestamp('reposted_at')->nullable()->after('posted_at');
            $table->string('repost_remote_id')->nullable()->after('reposted_at');
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->boolean('auto_repost')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropColumn(['reposted_at', 'repost_remote_id']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('auto_repost');
        });
    }
};
