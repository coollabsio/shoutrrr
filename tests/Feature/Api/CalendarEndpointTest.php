<?php

use App\Models\Post;

test('returns scheduled posts for a month', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id,
        'status' => 'scheduled',
        'scheduled_at' => now()->startOfMonth()->addDays(3),
    ]);
    $month = now()->format('Y-m');

    $this->withToken($token)->getJson("/api/v1/calendar?month={$month}")
        ->assertOk()
        ->assertJsonPath('month', $month)
        ->assertJsonPath('posts.0.id', $post->id);
});

test('rejects a malformed month', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->getJson('/api/v1/calendar?month=2026')->assertStatus(422);
});

test('rejects a semantically invalid month', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->getJson('/api/v1/calendar?month=2026-99')->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/calendar?month=2026-00')->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/calendar?month=2026-13')->assertStatus(422);
});

test('excludes posts from other workspaces', function () {
    [$user, $workspace, $token] = issuedKey();
    $mine = Post::factory()->for($workspace)->create([
        'author_id' => $user->id,
        'status' => 'scheduled',
        'scheduled_at' => now()->startOfMonth()->addDays(2),
    ]);
    $other = Post::factory()->create([
        'status' => 'scheduled',
        'scheduled_at' => now()->startOfMonth()->addDays(2),
    ]);
    $month = now()->format('Y-m');

    $ids = collect(
        $this->withToken($token)->getJson("/api/v1/calendar?month={$month}")->assertOk()->json('posts')
    )->pluck('id');

    expect($ids)->toContain($mine->id)->not->toContain($other->id);
});
