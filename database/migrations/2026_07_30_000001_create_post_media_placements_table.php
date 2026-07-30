<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_media_placements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_target_id')->constrained('post_targets')->cascadeOnDelete();
            $table->foreignUuid('post_media_id')->constrained('post_media')->cascadeOnDelete();
            // Stable authored-segment identity: '__head__' for the first segment,
            // otherwise the opening sectionBreak node's break_id.
            $table->string('segment_ref');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['post_target_id', 'post_media_id']);
        });

        Schema::table('post_targets', function (Blueprint $table): void {
            // Ordered break_ids for this target's authored segments (length = segments-1).
            $table->json('segment_breaks')->nullable()->after('sections');
            // Per resolved section: the authored-segment index it came from.
            $table->json('section_sources')->nullable()->after('segment_breaks');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropColumn(['segment_breaks', 'section_sources']);
        });
        Schema::dropIfExists('post_media_placements');
    }
};
