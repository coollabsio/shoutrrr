<?php

use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Support\Facades\Queue;

use function issuedKey;

test('schedules a post for a future time', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $when = now()->addDay()->toIso8601String();

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/schedule", ['scheduled_at' => $when])
        ->assertOk()
        ->assertJsonPath('post.status', 'scheduled');
});

test('rejects a past scheduled time', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/schedule", [
        'scheduled_at' => now()->subDay()->toIso8601String(),
    ])->assertStatus(422);
});

test('publishing dispatches and returns 202', function () {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/publish")
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued');
});

test('a read-only key cannot publish', function () {
    [$user, $workspace, $token] = issuedKey('read');
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/publish")->assertForbidden();
});

test('queueing returns 422 when no posting-schedule slot is available', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/queue")->assertStatus(422);
});

test('retrying a failed target dispatches and returns 202', function () {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $target = PostTarget::factory()->for($post)->failed()->create();

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued');

    expect($target->fresh()->status)->toBe(PostTargetStatus::Pending);
});

test('retrying a non-failed target is rejected', function () {
    [$user, $workspace, $token] = issuedKey();
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    $target = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Published->value]);

    $this->withToken($token)->postJson("/api/v1/posts/{$post->id}/targets/{$target->id}/retry")
        ->assertStatus(422);
});
