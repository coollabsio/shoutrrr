<?php

// tests/Feature/Compose/PostMediaPlacementsMigrationTest.php
use Illuminate\Support\Facades\Schema;

test('placements table and target provenance columns exist', function (): void {
    expect(Schema::hasTable('post_media_placements'))->toBeTrue();
    expect(Schema::hasColumns('post_media_placements', [
        'id', 'post_target_id', 'post_media_id', 'segment_ref', 'position', 'created_at', 'updated_at',
    ]))->toBeTrue();
    expect(Schema::hasColumns('post_targets', ['segment_breaks', 'section_sources']))->toBeTrue();
});
