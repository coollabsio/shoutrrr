<?php

use App\Enums\PostStatus;
use App\Jobs\DeletePostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Queue;

test('creates a draft post', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'Hello from the API',
        'destination' => ['kind' => 'all'],
    ])
        ->assertCreated()
        ->assertJsonPath('post.base_text', 'Hello from the API');
});

test('validates destination', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->postJson('/api/v1/posts', ['base_text' => 'x'])
        ->assertStatus(422);
});

test('updates a draft post', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'Edited',
        'destination' => ['kind' => 'all'],
    ])
        ->assertOk()
        ->assertJsonPath('post.base_text', 'Edited');
});

test('deletes a draft post', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id, 'status' => 'draft']);

    $this->withToken($token)->deleteJson("/api/v1/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(Post::whereKey($post->id)->exists())->toBeFalse();
});

test('returns 409 on a stale write', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'Edited',
        'destination' => ['kind' => 'all'],
        'expected_updated_at' => '2020-01-01T00:00:00+00:00',
    ])
        ->assertStatus(409);
});

test('deleting a published post dispatches remote deletion for targets with a remote_id', function () {
    Queue::fake();

    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create([
        'author_id' => $user->id,
        'status' => PostStatus::Published->value,
    ]);
    PostTarget::factory()->for($post)->create(['remote_id' => 'remote-abc']);

    $this->withToken($token)->deleteJson("/api/v1/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true)
        ->assertJsonPath('remote', true);

    Queue::assertPushed(DeletePostTarget::class);

    $post->refresh();
    expect(Post::whereKey($post->id)->exists())->toBeTrue()
        ->and($post->status)->toBe(PostStatus::Deleted);
});
