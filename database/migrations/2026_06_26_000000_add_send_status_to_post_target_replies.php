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
            // Null for inbound replies and already-sent rows; set only while an
            // outgoing media reply is in flight (sending → sent | failed).
            $table->string('send_status')->nullable()->after('is_ours');
        });
    }

    public function down(): void
    {
        Schema::table('post_target_replies', function (Blueprint $table): void {
            $table->dropColumn('send_status');
        });
    }
};
