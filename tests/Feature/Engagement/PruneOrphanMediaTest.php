<?php

// tests/Feature/Engagement/PruneOrphanMediaTest.php
use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

test('it prunes orphan media older than the cutoff and keeps recent + attached media', function () {
    $old = PostMedia::factory()->create(['post_id' => null, 'created_at' => now()->subHours(12)]);
    $recent = PostMedia::factory()->create(['post_id' => null, 'created_at' => now()->subMinutes(5)]);
    $attached = PostMedia::factory()->for(Post::factory())->create(['created_at' => now()->subHours(12)]);

    $this->artisan('media:prune-uploads')->assertSuccessful();

    expect(PostMedia::withoutGlobalScopes()->find($old->id))->toBeNull();
    expect(PostMedia::withoutGlobalScopes()->find($recent->id))->not->toBeNull();
    expect(PostMedia::withoutGlobalScopes()->find($attached->id))->not->toBeNull();
});
