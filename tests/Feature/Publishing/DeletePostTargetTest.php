<?php

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Jobs\DeletePostTarget;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\BlueskyPublishConnector;
use App\Services\Publishing\Connectors\LinkedInConnector;
use App\Services\Publishing\PublishConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function remoteDeleteTarget(): PostTarget
{
    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::X->value,
        'token_expires_at' => now()->addHour(),
    ]);
    ConnectedAccountSecret::factory()->create(['connected_account_id' => $account->id, 'access_token' => 'tok']);

    return PostTarget::factory()->for($post)->published()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::X->value,
        'remote_ids' => ['111'],
    ]);
}

test('it transitions a published target to deleted', function () {
    Http::fake();

    $target = remoteDeleteTarget();

    (new DeletePostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Deleted);
});

test('it does not mark a target deleted when remote deletion fails', function () {
    Http::fake([
        '*' => Http::response(['error' => 'temporarily unavailable'], 500),
    ]);

    $target = remoteDeleteTarget();

    expect(fn () => (new DeletePostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
    ))->toThrow(RequestException::class);

    expect($target->refresh()->status)->toBe(PostTargetStatus::Deleting);
});

test('it treats an already-missing remote target as deleted', function () {
    Http::fake([
        '*' => Http::response(['error' => 'not found'], 404),
    ]);

    $target = remoteDeleteTarget();

    (new DeletePostTarget($target))->handle(
        app(PublishConnectorRegistry::class),
        app(TokenManager::class),
    );

    expect($target->refresh()->status)->toBe(PostTargetStatus::Deleted);
});

test('it marks the target failed after queued delete retries are exhausted', function () {
    $target = remoteDeleteTarget();

    (new DeletePostTarget($target))->failed(new Exception('remote delete failed'));

    expect($target->refresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->error_message)->toBe('remote delete failed');
});

test('bluesky remote delete failures throw instead of being acknowledged', function () {
    Http::fake([
        '*' => Http::response(['error' => 'upstream failed'], 500),
    ]);

    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->bluesky()->create();
    $target = PostTarget::factory()->for($post)->published()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Bluesky->value,
        'remote_ids' => ['at://did:plc:example/app.bsky.feed.post/remote-rkey'],
    ]);

    expect(fn () => app(BlueskyPublishConnector::class)->delete($target, [
        'session' => ['accessJwt' => 'jwt', 'pds' => 'https://bsky.social'],
    ]))->toThrow(RequestException::class);
});

test('linkedin remote delete failures throw instead of being acknowledged', function () {
    Http::fake([
        '*' => Http::response(['error' => 'upstream failed'], 500),
    ]);

    $post = Post::factory()->create();
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::LinkedIn->value,
        'token_expires_at' => now()->addHour(),
    ]);
    $target = PostTarget::factory()->for($post)->published()->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::LinkedIn->value,
        'remote_id' => 'urn:li:share:123',
    ]);

    expect(fn () => app(LinkedInConnector::class)->delete($target, [
        'access_token' => 'tok',
    ]))->toThrow(RequestException::class);
});
