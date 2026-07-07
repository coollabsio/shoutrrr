<?php

use App\Models\Post;

test('lists posts in the bound workspace', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->getJson('/api/v1/posts')
        ->assertOk()
        ->assertJsonPath('posts.0.id', $post->id);
});

test('filters posts by status', function () {
    [$user, $workspace, $token] = issuedKey();
    Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => 'draft']);
    Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => 'published']);

    $response = $this->withToken($token)->getJson('/api/v1/posts?status=draft')->assertOk();

    expect($response->json('posts'))->toHaveCount(1);
});

test('shows one post', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->getJson("/api/v1/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('post.id', $post->id);
});

test('a cross-tenant post id is 404', function () {
    [, , $token] = issuedKey();
    $other = Post::factory()->create(); // different workspace

    $this->withToken($token)->getJson("/api/v1/posts/{$other->id}")->assertNotFound();
});
