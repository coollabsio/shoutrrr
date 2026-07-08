<?php

use App\Models\Post;

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
        ->assertJsonCount(1, 'data');
});

test('revokes a share', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $id = $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/shares")->json('id');

    $this->withToken($token)->deleteJson("/api/v1/posts/{$post->id}/shares/{$id}")
        ->assertOk()
        ->assertJsonPath('revoked', true);
});

test('cannot revoke a share by pairing it with the wrong post', function () {
    [$user, $workspace, $token] = issuedKey();
    $postA = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $postB = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $id = $this->withToken($token)->postJson("/api/v1/posts/{$postB->id}/shares")->json('id');

    $this->withToken($token)->deleteJson("/api/v1/posts/{$postA->id}/shares/{$id}")
        ->assertNotFound();

    $this->withToken($token)->getJson("/api/v1/posts/{$postB->id}/shares")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a read-only key cannot create or revoke shares', function () {
    [$user, $workspace, $token] = issuedKey('read');
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/shares")
        ->assertForbidden();

    $this->withToken($token)->deleteJson("/api/v1/posts/{$post->id}/shares/does-not-matter")
        ->assertForbidden();
});
