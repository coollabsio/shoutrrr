<?php

declare(strict_types=1);

use App\Enums\EngagementStatus;
use App\Enums\MessageDirection;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\PostMedia;
use App\Services\Messaging\Connectors\XDirectMessageConnector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('x fetchConversations groups dm events by conversation', function () {
    Http::fake([
        'api.twitter.com/2/dm_events*' => Http::response([
            'data' => [
                ['id' => 'e1', 'event_type' => 'MessageCreate', 'text' => 'hi', 'sender_id' => '999', 'dm_conversation_id' => 'c-1', 'created_at' => '2026-07-20T10:00:00Z'],
                ['id' => 'e2', 'event_type' => 'MessageCreate', 'text' => 'again', 'sender_id' => '999', 'dm_conversation_id' => 'c-1', 'created_at' => '2026-07-20T10:05:00Z'],
            ],
            'includes' => ['users' => [['id' => '999', 'username' => 'alice', 'name' => 'Alice', 'profile_image_url' => 'https://x/a.jpg']]],
            'meta' => [],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '123']);
    $result = app(XDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->conversations)->toHaveCount(1);
    expect($result->conversations[0]->remoteConversationId)->toBe('c-1');
    expect($result->conversations[0]->counterpartHandle)->toBe('@alice');
    expect($result->conversations[0]->messages)->toHaveCount(2);
    expect($result->conversations[0]->messages[0]->direction)->toBe(MessageDirection::Inbound);
});

test('x fetch maps 429 to rate limited', function () {
    Http::fake(['api.twitter.com/2/dm_events*' => Http::response('slow down', 429, ['x-rate-limit-reset' => (string) (time() + 60)])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $result = app(XDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);
    expect($result->status)->toBe(EngagementStatus::RateLimited);
    expect($result->retryAfterSeconds)->toBeGreaterThan(0);
});

// A conversation where only our own messages fall in the fetch window has no
// inbound sender to read the counterpart off, but X names 1:1 conversations
// `{userA}-{userB}`, so it is still recoverable — and then looked up by id.
test('x resolves the counterpart of an outbound-only conversation from the conversation id', function () {
    Http::fake([
        'api.twitter.com/2/dm_events*' => Http::response([
            'data' => [
                ['id' => 'e1', 'event_type' => 'MessageCreate', 'text' => 'you around?', 'sender_id' => '111', 'dm_conversation_id' => '111-222', 'created_at' => '2026-07-20T10:00:00Z'],
            ],
            'includes' => ['users' => [['id' => '111', 'username' => 'me', 'name' => 'Me']]],
            'meta' => [],
        ]),
        'api.twitter.com/2/users*' => Http::response([
            'data' => [['id' => '222', 'username' => 'bob', 'name' => 'Bob', 'profile_image_url' => 'https://x/b.jpg']],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '111']);
    $result = app(XDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->conversations[0]->counterpartRemoteId)->toBe('222');
    expect($result->conversations[0]->counterpartHandle)->toBe('@bob');
    expect($result->conversations[0]->counterpartName)->toBe('Bob');
    expect($result->conversations[0]->messages[0]->direction)->toBe(MessageDirection::Outbound);
});

test('x skips the user lookup when every counterpart is already expanded', function () {
    Http::fake([
        'api.twitter.com/2/dm_events*' => Http::response([
            'data' => [
                ['id' => 'e1', 'event_type' => 'MessageCreate', 'text' => 'hi', 'sender_id' => '999', 'dm_conversation_id' => '111-999', 'created_at' => '2026-07-20T10:00:00Z'],
            ],
            'includes' => ['users' => [['id' => '999', 'username' => 'alice', 'name' => 'Alice']]],
            'meta' => [],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '111']);
    $result = app(XDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);

    expect($result->conversations[0]->counterpartHandle)->toBe('@alice');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/users'));
});

test('x leaves a group conversation counterpart unresolved', function () {
    Http::fake([
        'api.twitter.com/2/dm_events*' => Http::response([
            'data' => [
                ['id' => 'e1', 'event_type' => 'MessageCreate', 'text' => 'hey all', 'sender_id' => '111', 'dm_conversation_id' => 'group-abc-def', 'created_at' => '2026-07-20T10:00:00Z'],
            ],
            'includes' => ['users' => []],
            'meta' => [],
        ]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::X, 'remote_account_id' => '111']);
    $result = app(XDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);

    expect($result->conversations[0]->counterpartRemoteId)->toBeNull();
    expect($result->conversations[0]->counterpartHandle)->toBeNull();
});

test('x sendMessage posts to the conversation endpoint', function () {
    Http::fake(['api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-1']], 201)]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $convo = Conversation::factory()->for($account, 'account')->create(['remote_conversation_id' => 'c-1']);
    $result = app(XDirectMessageConnector::class)->sendMessage($account, $convo, 'yo', ['access_token' => 'tok']);
    expect($result->isOk())->toBeTrue();
    expect($result->remoteMessageId)->toBe('sent-1');
});

/** @return array{0: ConnectedAccount, 1: Conversation} */
function xDmSender(): array
{
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X]);
    $convo = Conversation::factory()->for($account, 'account')->create(['remote_conversation_id' => 'c-1']);

    return [$account, $convo];
}

function xDmImage(string $mime = 'image/jpeg', string $name = 'x.jpg'): PostMedia
{
    Storage::fake('public');
    $path = Storage::disk('public')->putFileAs('media/ws', UploadedFile::fake()->image($name), $name);

    return PostMedia::factory()->create(['disk' => 'public', 'path' => $path, 'kind' => 'image', 'mime' => $mime]);
}

/** The multipart upload body is not decoded by the HTTP fake, so match the raw part. */
function xDmUploadCarried(string $field, string $value): callable
{
    return function ($request) use ($field, $value): bool {
        if (! str_contains($request->url(), 'api.x.com/2/media/upload')) {
            return false;
        }

        $body = $request->body();

        return str_contains($body, 'name="'.$field.'"') && str_contains($body, $value);
    };
}

test('x sendMessage uploads an image as a dm attachment and omits empty text', function () {
    Http::fake([
        'api.x.com/2/media/upload' => Http::response(['data' => ['id' => '111']]),
        'api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-img']], 201),
    ]);

    [$account, $convo] = xDmSender();

    $result = app(XDirectMessageConnector::class)->sendMessage($account, $convo, '', ['access_token' => 'tok'], [xDmImage()]);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteMessageId)->toBe('sent-img');

    // Without both of these X rejects the send with "You are not permitted to
    // attach this media to a DM event", even though the id posts fine as a tweet.
    Http::assertSent(xDmUploadCarried('media_category', 'dm_image'));
    Http::assertSent(xDmUploadCarried('shared', 'true'));

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/messages')) {
            return false;
        }

        return ($request['attachments'][0]['media_id'] ?? null) === '111'
            && ! array_key_exists('text', $request->data());
    });
});

test('x sendMessage sends text alongside an attachment', function () {
    Http::fake([
        'api.x.com/2/media/upload' => Http::response(['data' => ['id' => '112']]),
        'api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-both']], 201),
    ]);

    [$account, $convo] = xDmSender();

    $result = app(XDirectMessageConnector::class)->sendMessage($account, $convo, 'look at this', ['access_token' => 'tok'], [xDmImage()]);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/messages')
        && $request['text'] === 'look at this'
        && ($request['attachments'][0]['media_id'] ?? null) === '112');
});

test('x sendMessage categorises an animated gif as dm_gif', function () {
    Http::fake([
        'api.x.com/2/media/upload' => Http::response(['data' => ['id' => '113']]),
        'api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-gif']], 201),
    ]);

    [$account, $convo] = xDmSender();

    $result = app(XDirectMessageConnector::class)->sendMessage($account, $convo, '', ['access_token' => 'tok'], [xDmImage('image/gif', 'x.gif')]);

    expect($result->isOk())->toBeTrue();
    Http::assertSent(xDmUploadCarried('media_category', 'dm_gif'));
});

test('x sendMessage uploads a video via chunked initialize/append/finalize as dm_video', function () {
    Http::fake([
        'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => '222']]),
        'api.x.com/2/media/upload/222/append' => Http::response(null, 204),
        'api.x.com/2/media/upload/222/finalize' => Http::response(['data' => ['id' => '222']]),
        'api.x.com/2/media/upload*' => Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]]),
        'api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'sent-vid']], 201),
    ]);

    Storage::fake('public');
    // Real bytes: UploadedFile::fake()->create() reports a size but writes nothing,
    // so the append leg would never run.
    $path = 'media/ws/v.mp4';
    Storage::disk('public')->put($path, str_repeat('v', 2048));
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => $path, 'kind' => 'video', 'mime' => 'video/mp4']);

    [$account, $convo] = xDmSender();

    $result = app(XDirectMessageConnector::class)->sendMessage($account, $convo, '', ['access_token' => 'tok'], [$media]);

    expect($result->isOk())->toBeTrue();
    expect($result->remoteMessageId)->toBe('sent-vid');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/media/upload/initialize')
        && $request['media_category'] === 'dm_video'
        && $request['shared'] === true);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/media/upload/222/append'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/media/upload/222/finalize'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/messages')
        && ($request['attachments'][0]['media_id'] ?? null) === '222');
});

test('x sendMessage fails without sending when the media upload fails', function () {
    Http::fake([
        'api.x.com/2/media/upload' => Http::response('media too large', 400),
        'api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'never']], 201),
    ]);

    [$account, $convo] = xDmSender();

    $result = app(XDirectMessageConnector::class)->sendMessage($account, $convo, 'hi', ['access_token' => 'tok'], [xDmImage()]);

    expect($result->isOk())->toBeFalse();
    expect($result->status)->toBe(EngagementStatus::Failed);
    expect($result->excerpt)->toContain('media too large');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/messages'));
});

test('x sendMessage reports an unsupported media upload as unsupported', function () {
    Http::fake([
        'api.x.com/2/media/upload' => Http::response('not permitted', 403),
        'api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'never']], 201),
    ]);

    [$account, $convo] = xDmSender();

    $result = app(XDirectMessageConnector::class)->sendMessage($account, $convo, 'hi', ['access_token' => 'tok'], [xDmImage()]);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/messages'));
});

test('x sendMessage reports a slow transcode as retriable rather than blocking the request', function () {
    Http::fake([
        'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => '333']]),
        'api.x.com/2/media/upload/333/append' => Http::response(null, 204),
        'api.x.com/2/media/upload/333/finalize' => Http::response(['data' => ['id' => '333']]),
        // Beyond the poll budget, so it bails without ever sleeping.
        'api.x.com/2/media/upload*' => Http::response(['data' => ['processing_info' => [
            'state' => 'in_progress',
            'check_after_secs' => 600,
        ]]]),
        'api.twitter.com/2/dm_conversations/*/messages' => Http::response(['data' => ['dm_event_id' => 'never']], 201),
    ]);

    Storage::fake('public');
    $path = 'media/ws/slow.mp4';
    Storage::disk('public')->put($path, str_repeat('v', 2048));
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => $path, 'kind' => 'video', 'mime' => 'video/mp4']);

    [$account, $convo] = xDmSender();

    $result = app(XDirectMessageConnector::class)->sendMessage($account, $convo, '', ['access_token' => 'tok'], [$media]);

    expect($result->isOk())->toBeFalse();
    // Retriable "still processing", not a hard failure — carries X's retry hint.
    expect($result->status)->toBe(EngagementStatus::RateLimited);
    expect($result->retryAfterSeconds)->toBe(600);
    expect($result->excerpt)->toContain('still being processed');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'dm_conversations'));
});
