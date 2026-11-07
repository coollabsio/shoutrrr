<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Exceptions\TransientTokenRefreshException;
use App\Jobs\DeletePostTarget;
use App\Jobs\PublishPostTarget;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use App\Services\Engagement\EngagementConnectorRegistry;
use App\Services\Messaging\Connectors\XDirectMessageConnector;
use App\Services\Publishing\Connectors\ThreadsConnector;
use App\Services\Publishing\Connectors\XConnector;
use App\Support\ConnectionFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function rawConnectionError(): string
{
    return 'cURL error 7: Failed to connect via 127.0.0.1 for https://graph.example/v1.0/SECRET';
}

function failEveryPlatformConnection(): void
{
    Http::fake(fn () => throw new ConnectionException(rawConnectionError()));
}

/** @return array{ConnectedAccount, PostTargetReply, array<string, mixed>} */
function replyFixture(Platform $platform): array
{
    $account = ConnectedAccount::factory()->create([
        'platform' => $platform->value,
        'remote_account_id' => 'REMOTE1',
    ]);
    $reply = PostTargetReply::factory()->create([
        'platform' => $platform,
        'remote_reply_id' => $platform === Platform::LinkedIn
            ? 'urn:li:comment:(urn:li:activity:1,2)'
            : 'at://did:plc:abc/app.bsky.feed.post/R1',
        'parent_remote_id' => 'MEDIA1',
    ]);

    return [$account, $reply, [
        'access_token' => 't',
        'session' => ['pds' => 'https://pds.example', 'accessJwt' => 'jwt'],
    ]];
}

dataset('reply platforms', [
    'x' => [Platform::X],
    'bluesky' => [Platform::Bluesky],
    'linkedin' => [Platform::LinkedIn],
    'facebook' => [Platform::Facebook],
    'instagram' => [Platform::Instagram],
    'threads' => [Platform::Threads],
]);

test('a reply that cannot reach the platform gets a safe message', function (Platform $platform) {
    failEveryPlatformConnection();
    Log::spy();
    [$account, $reply, $credentials] = replyFixture($platform);

    $result = app(EngagementConnectorRegistry::class)->for($platform)
        ->postReply($account, $reply, 'thanks', $credentials);

    expect($result->message)->toBe(ConnectionFailure::MESSAGE);

    Log::shouldHaveReceived('warning')
        ->with('platform.connection.failed', ['message' => rawConnectionError()]);
})->with('reply platforms');

test('a reply action that cannot reach the platform gets a safe message', function (Platform $platform) {
    failEveryPlatformConnection();
    [$account, $reply, $credentials] = replyFixture($platform);

    $result = app(EngagementConnectorRegistry::class)->for($platform)
        ->deleteReply($account, $reply, $credentials);

    expect($result->message)->toBe(ConnectionFailure::MESSAGE);
})->with('reply platforms');

test('a direct message that cannot reach the platform gets a safe message', function () {
    failEveryPlatformConnection();

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X->value]);
    $conversation = Conversation::factory()->create([
        'platform' => Platform::X,
        'connected_account_id' => $account->id,
    ]);

    $result = app(XDirectMessageConnector::class)
        ->sendMessage($account, $conversation, 'hello', ['access_token' => 't']);

    expect($result->excerpt)->toBe(ConnectionFailure::MESSAGE);
});

test('a publish that cannot reach the platform keeps its retryable kind and gets a safe message', function (string $connector, Platform $platform) {
    failEveryPlatformConnection();

    $result = app($connector)->publish(new PublishContext(
        target: PostTarget::factory()->create(['platform' => $platform->value]),
        segments: ['hello'],
        media: [],
        account: ConnectedAccount::factory()->create(['platform' => $platform->value]),
        credentials: ['access_token' => 't'],
    ));

    expect($result->errorKind)->toBe(ErrorKind::Network)
        ->and($result->errorMessage)->toBe(ConnectionFailure::MESSAGE)
        ->and($result->responseExcerpt)->toBe(rawConnectionError());
})->with([
    'threads' => [ThreadsConnector::class, Platform::Threads],
    'x' => [XConnector::class, Platform::X],
]);

test('a job that dies on a connection error stores a safe message', function (string $job) {
    $target = PostTarget::factory()->create();

    (new $job($target))->failed(new ConnectionException(rawConnectionError()));

    expect($target->refresh()->error_message)->toBe(ConnectionFailure::MESSAGE);
})->with([
    'delete' => [DeletePostTarget::class],
    'publish' => [PublishPostTarget::class],
]);

test('a job that dies on a connection error logs it once', function () {
    Log::spy();

    (new PublishPostTarget(PostTarget::factory()->create()))
        ->failed(new ConnectionException(rawConnectionError()));

    Log::shouldHaveReceived('warning')->once();
});

test('safeMessage replaces a wrapped connection error', function () {
    $transient = new TransientTokenRefreshException(
        'Threads token refresh failed: '.rawConnectionError(),
        previous: new ConnectionException(rawConnectionError()),
    );

    expect(ConnectionFailure::safeMessage($transient))->toBe(ConnectionFailure::MESSAGE);
});

test('safeMessage keeps the text of any other error', function () {
    expect(ConnectionFailure::safeMessage(new RuntimeException('Threads rate limit hit')))
        ->toBe('Threads rate limit hit');
});
