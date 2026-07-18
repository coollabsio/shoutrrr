<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_pages', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // One legal presence per workspace: the unique constraint makes the
            // workspace <-> legal_page relation strictly one-to-one.
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();

            // The owner-chosen public slug. Unique across every workspace so the
            // `/{slug}/terms` and `/{slug}/privacy` routes resolve unambiguously.
            // Deliberately separate from `workspaces.slug` so publishing legal
            // pages never exposes the workspace's internal identifier.
            $table->string('slug')->unique();

            // Markdown sources; a null publish timestamp marks the document as an
            // unpublished draft that the public routes must treat as absent.
            $table->longText('terms_body')->nullable();
            $table->timestamp('terms_published_at')->nullable();

            $table->longText('privacy_body')->nullable();
            $table->timestamp('privacy_published_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_pages');
    }
};
