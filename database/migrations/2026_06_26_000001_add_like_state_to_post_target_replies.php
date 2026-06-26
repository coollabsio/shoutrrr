<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_target_replies', function (Blueprint $table): void {
            $table->timestamp('liked_at')->nullable()->after('our_reply_remote_id');
            $table->string('like_remote_id')->nullable()->after('liked_at');
        });
    }

    public function down(): void
    {
        Schema::table('post_target_replies', function (Blueprint $table): void {
            $table->dropColumn(['liked_at', 'like_remote_id']);
        });
    }
};
