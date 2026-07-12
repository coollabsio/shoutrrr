<?php

use App\Models\Post;

test('lists posts in the bound workspace', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->getJson('/api/v1/posts')
        ->assertOk()
        ->assertJsonPath('data.0.id', $post->id);
});

test('filters posts by status', function () {
    [$user, $workspace, $token] = issuedKey();
    Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => 'draft']);
    Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => 'published']);

    $response = $this->withToken($token)->getJson('/api/v1/posts?status=draft')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

test('filters posts by q as a case-insensitive substring match', function () {
    [$user, $workspace, $token] = issuedKey();
    $match = Post::factory()->for($workspace)->create(['author_id' => $user->id, 'base_text' => 'Launch announcement']);
    Post::factory()->for($workspace)->create(['author_id' => $user->id, 'base_text' => 'weekly recap']);

    $response = $this->withToken($token)->getJson('/api/v1/posts?q=LAUNCH')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($match->id);
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

test('per_page caps the page and reports has_more', function () {
    [$user, $workspace, $token] = issuedKey();
    Post::factory()->for($workspace)->count(3)->create(['author_id' => $user->id]);

    $response = $this->withToken($token)->getJson('/api/v1/posts?per_page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('pagination.has_more'))->toBeTrue();
    expect($response->json('pagination.per_page'))->toBe(2);
    expect($response->json('pagination.next_cursor'))->not->toBeNull();
});

test('following next_cursor returns the remaining rows and has_more is false on the last page', function () {
    [$user, $workspace, $token] = issuedKey();
    Post::factory()->for($workspace)->count(3)->create(['author_id' => $user->id]);

    $first = $this->withToken($token)->getJson('/api/v1/posts?per_page=2')->assertOk();
    $cursor = $first->json('pagination.next_cursor');

    $second = $this->withToken($token)->getJson('/api/v1/posts?per_page=2&cursor='.$cursor)->assertOk();

    expect($second->json('data'))->toHaveCount(1);
    expect($second->json('pagination.has_more'))->toBeFalse();
});

test('default page size is used when per_page is omitted', function () {
    [$user, $workspace, $token] = issuedKey();
    Post::factory()->for($workspace)->count(3)->create(['author_id' => $user->id]);

    $response = $this->withToken($token)->getJson('/api/v1/posts')->assertOk();

    expect($response->json('data'))->toHaveCount(3);
    expect($response->json('pagination.per_page'))->toBe(25);
    expect($response->json('pagination.has_more'))->toBeFalse();
});
