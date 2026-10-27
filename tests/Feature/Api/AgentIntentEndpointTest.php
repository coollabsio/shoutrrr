<?php

use App\Enums\PostOrigin;
use App\Enums\PostStatus;
use App\Jobs\PublishPostTarget;
use App\Models\AgentIntent;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Support\Facades\Queue;

test('publish now creates an agent post and is idempotent', function () {
    Queue::fake();
    [$user, $workspace, $token] = issuedKey();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $payload = [
        'text' => 'Hello from an agent',
        'destination' => ['kind' => 'all'],
    ];

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'intent-1')
        ->postJson('/api/v1/intents/publish-now', $payload)
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('idempotent_replay', false)
        ->assertJsonPath('post.base_text', 'Hello from an agent');

    $post = Post::query()->where('workspace_id', $workspace->id)->sole();
    expect($post->origin)->toBe(PostOrigin::Agent)
        ->and($post->status)->toBe(PostStatus::Publishing)
        ->and($post->author_id)->toBe($user->id);

    Queue::assertPushed(PublishPostTarget::class, 1);

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'intent-1')
        ->postJson('/api/v1/intents/publish-now', $payload)
        ->assertOk()
        ->assertJsonPath('status', 'replayed')
        ->assertJsonPath('idempotent_replay', true)
        ->assertJsonPath('post.id', $post->id);

    expect(Post::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
    Queue::assertPushed(PublishPostTarget::class, 1);
});

test('the same idempotency key rejects a different publish payload', function () {
    Queue::fake();
    [, $workspace, $token] = issuedKey();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'intent-2')
        ->postJson('/api/v1/intents/publish-now', [
            'text' => 'First',
            'destination' => ['kind' => 'all'],
        ])
        ->assertStatus(202);

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'intent-2')
        ->postJson('/api/v1/intents/publish-now', [
            'text' => 'Second',
            'destination' => ['kind' => 'all'],
        ])
        ->assertStatus(409);

    expect(Post::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('publish now requires an idempotency key', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)
        ->postJson('/api/v1/intents/publish-now', [
            'text' => 'Hello',
            'destination' => ['kind' => 'all'],
        ])
        ->assertStatus(422);
});

test('a read-only key cannot publish now', function () {
    [, , $token] = issuedKey('read');

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'intent-read')
        ->postJson('/api/v1/intents/publish-now', [
            'text' => 'Hello',
            'destination' => ['kind' => 'all'],
        ])
        ->assertForbidden();
});

test('schedule at creates a scheduled agent post and replays it', function () {
    [$user, $workspace, $token] = issuedKey();
    $when = now()->addDay()->toIso8601String();

    $payload = [
        'text' => 'See you tomorrow',
        'destination' => ['kind' => 'all'],
        'scheduled_at' => $when,
    ];

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'sched-1')
        ->postJson('/api/v1/intents/schedule-at', $payload)
        ->assertOk()
        ->assertJsonPath('idempotent_replay', false)
        ->assertJsonPath('post.status', 'scheduled');

    $post = Post::query()->where('workspace_id', $workspace->id)->sole();
    expect($post->origin)->toBe(PostOrigin::Agent)
        ->and($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->author_id)->toBe($user->id);

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'sched-1')
        ->postJson('/api/v1/intents/schedule-at', $payload)
        ->assertOk()
        ->assertJsonPath('idempotent_replay', true)
        ->assertJsonPath('post.id', $post->id);

    expect(Post::query()->count())->toBe(1);
});

test('schedule at rejects a time in the past', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'sched-past')
        ->postJson('/api/v1/intents/schedule-at', [
            'text' => 'Too late',
            'destination' => ['kind' => 'all'],
            'scheduled_at' => now()->subMinute()->toIso8601String(),
        ])
        ->assertStatus(422);

    expect(Post::query()->count())->toBe(0);
});

test('publish now accepts a thread with media and rejects the same key with different segments', function () {
    Queue::fake();
    [, $workspace, $token] = issuedKey();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);
    $media = PostMedia::factory()->for($workspace)->create(['post_id' => null]);

    $payload = [
        'segments' => ['one', 'two'],
        'media_ids' => [$media->id],
        'destination' => ['kind' => 'all'],
    ];

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'intent-thread')
        ->postJson('/api/v1/intents/publish-now', $payload)
        ->assertStatus(202)
        ->assertJsonPath('post.segments', ['one', 'two'])
        ->assertJsonPath('post.media.0.id', $media->id);

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'intent-thread')
        ->postJson('/api/v1/intents/publish-now', [...$payload, 'segments' => ['one', 'changed']])
        ->assertStatus(409);

    expect(Post::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('a text-only intent keeps the fingerprint it had before media and threads existed', function () {
    Queue::fake();
    [, $workspace, $token] = issuedKey();
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]);

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'intent-legacy')
        ->postJson('/api/v1/intents/publish-now', ['text' => 'Plain', 'destination' => ['kind' => 'all']])
        ->assertStatus(202);

    $legacyHash = hash('sha256', json_encode([
        'action' => 'publish_now',
        'destination' => ['id' => null, 'kind' => 'all'],
        'scheduled_at' => null,
        'text' => 'Plain',
    ], JSON_THROW_ON_ERROR));

    expect(AgentIntent::query()->where('idempotency_key', 'intent-legacy')->sole()->request_hash)->toBe($legacyHash);
});

test('publish now requires text or segments', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)
        ->withHeader('Idempotency-Key', 'intent-empty')
        ->postJson('/api/v1/intents/publish-now', ['destination' => ['kind' => 'all']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');
});

test('openapi spec documents the intent endpoints', function () {
    $paths = $this->getJson('/api/v1/openapi.json')->json('paths');

    expect($paths)->toHaveKeys(['/intents/publish-now', '/intents/schedule-at']);
});
