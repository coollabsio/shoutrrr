<?php

use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\PostStatus;
use App\Jobs\DeletePostTarget;
use App\Models\ConnectedAccount;
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

test('creates a draft with a per-target format', function () {
    [, $workspace, $token] = issuedKey();
    $instagram = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram->value]);

    $response = $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'A reel',
        'destination' => ['kind' => 'account', 'id' => $instagram->id],
        'targets' => [['connected_account_id' => $instagram->id, 'format' => 'reels']],
    ]);

    $response->assertCreated()->assertJsonPath('post.targets.0.format', 'reels');

    expect(PostTarget::where('connected_account_id', $instagram->id)->sole()->format)->toBe(PostFormat::Reels);
});

test('updates a target format and keeps it on a later update that omits it', function () {
    [$user, $workspace, $token] = issuedKey();
    $facebook = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Facebook->value]);
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'Story time',
        'destination' => ['kind' => 'account', 'id' => $facebook->id],
        'targets' => [['connected_account_id' => $facebook->id, 'format' => 'story']],
    ])->assertOk()->assertJsonPath('post.targets.0.format', 'story');

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'Still a story',
        'destination' => ['kind' => 'account', 'id' => $facebook->id],
    ])->assertOk()->assertJsonPath('post.targets.0.format', 'story');
});

test('rejects an unknown format', function () {
    [, $workspace, $token] = issuedKey();
    $instagram = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram->value]);

    $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'x',
        'destination' => ['kind' => 'account', 'id' => $instagram->id],
        'targets' => [['connected_account_id' => $instagram->id, 'format' => 'carousel']],
    ])->assertStatus(422)->assertJsonValidationErrors(['targets.0.format']);
});

test('rejects a format the target platform does not offer', function () {
    [$user, $workspace, $token] = issuedKey();
    $x = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);

    $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'x',
        'destination' => ['kind' => 'account', 'id' => $x->id],
        'targets' => [['connected_account_id' => $x->id, 'format' => 'reels']],
    ])->assertStatus(422)->assertJsonValidationErrors(['targets.0.format']);

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'x',
        'destination' => ['kind' => 'account', 'id' => $x->id],
        'targets' => [['connected_account_id' => $x->id, 'format' => 'story']],
    ])->assertStatus(422)->assertJsonValidationErrors(['targets.0.format']);
});

test('rejects a format for an account outside the api key workspace', function () {
    [, , $token] = issuedKey();
    $foreign = ConnectedAccount::factory()->create(['platform' => Platform::Instagram->value]);

    $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'x',
        'destination' => ['kind' => 'all'],
        'targets' => [['connected_account_id' => $foreign->id, 'format' => 'reels']],
    ])->assertStatus(422)->assertJsonValidationErrors(['targets.0.connected_account_id']);
});

test('reports an unsupported format on the right target when several are sent', function () {
    [, $workspace, $token] = issuedKey();
    $instagram = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram->value]);
    $x = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);

    $response = $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'x',
        'destination' => ['kind' => 'all'],
        'targets' => [
            ['connected_account_id' => $instagram->id, 'format' => 'reels'],
            ['connected_account_id' => $x->id, 'format' => 'reels'],
        ],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['targets.1.format']);
    expect($response->json('errors'))->not->toHaveKey('targets.0.format');
});

test('stores tagged handles on an X target and keeps already resolved ids for unchanged handles', function () {
    [$user, $workspace, $token] = issuedKey();
    $x = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $post = Post::factory()->for($workspace)->create(['author_id' => $user->id]);
    PostTarget::factory()->for($post)->create([
        'connected_account_id' => $x->id,
        'platform' => Platform::X->value,
        'tagged_users' => [['handle' => 'Ada', 'id' => '11']],
    ]);

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'Photos',
        'destination' => ['kind' => 'account', 'id' => $x->id],
        'targets' => [['connected_account_id' => $x->id, 'tagged_handles' => ['@ada', 'grace', 'GRACE']]],
    ])->assertOk()->assertJsonPath('post.targets.0.tagged_handles', ['ada', 'grace']);

    expect(PostTarget::where('connected_account_id', $x->id)->sole()->tagged_users)->toBe([
        ['handle' => 'ada', 'id' => '11'],
        ['handle' => 'grace', 'id' => null],
    ]);

    $this->withToken($token)->patchJson("/api/v1/posts/{$post->id}", [
        'base_text' => 'Edited',
        'destination' => ['kind' => 'account', 'id' => $x->id],
    ])->assertOk()->assertJsonPath('post.targets.0.tagged_handles', ['ada', 'grace']);
});

test('rejects more than ten tagged handles or tags on a platform other than X', function () {
    [, $workspace, $token] = issuedKey();
    $x = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X->value]);
    $bluesky = ConnectedAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Bluesky->value]);

    $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'x',
        'destination' => ['kind' => 'account', 'id' => $x->id],
        'targets' => [['connected_account_id' => $x->id, 'tagged_handles' => array_map(fn (int $n): string => "user{$n}", range(1, 11))]],
    ])->assertStatus(422)->assertJsonValidationErrors(['targets.0.tagged_handles']);

    $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'x',
        'destination' => ['kind' => 'account', 'id' => $bluesky->id],
        'targets' => [['connected_account_id' => $bluesky->id, 'tagged_handles' => ['ada']]],
    ])->assertStatus(422)->assertJsonValidationErrors(['targets.0.tagged_handles']);
});
