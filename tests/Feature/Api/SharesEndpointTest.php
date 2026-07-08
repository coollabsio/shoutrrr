<?php

use App\Models\Post;

use function issuedKey;

test('creates a share link', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/shares")
        ->assertCreated()
        ->assertJsonStructure(['id', 'url', 'expires_at']);
});

test('lists active shares', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/shares")->assertCreated();

    $this->withToken($token)->getJson("/api/v1/posts/{$post->id}/shares")
        ->assertOk()
        ->assertJsonCount(1, 'shares');
});

test('revokes a share', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $id = $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/shares")->json('id');

    $this->withToken($token)->deleteJson("/api/v1/posts/{$post->id}/shares/{$id}")
        ->assertOk()
        ->assertJsonPath('revoked', true);
});
