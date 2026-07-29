<?php

declare(strict_types=1);

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\PostMedia;
use App\Services\Messaging\Connectors\FacebookDirectMessageConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('facebook lists messenger conversations', function () {
    Http::fake([
        'graph.facebook.com/*/conversations*' => Http::response(['data' => [[
            'id' => 'fb-convo-1',
            'participants' => ['data' => [['id' => 'page-id', 'name' => 'My Page'], ['id' => 'psid-bob', 'name' => 'Bob']]],
            'messages' => ['data' => [
                ['id' => 'fbm-1', 'from' => ['id' => 'psid-bob', 'name' => 'Bob'], 'message' => 'yo', 'created_time' => now()->subHour()->toIso8601String()],
            ]],
        ]]]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page-id']);
    $result = app(FacebookDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'page-tok'], null);

    expect($result->isOk())->toBeTrue();
    expect($result->conversations[0]->counterpartRemoteId)->toBe('psid-bob');
    expect($result->conversations[0]->messagingWindowExpiresAt)->not->toBeNull();
});

test('facebook send uses messaging_type RESPONSE', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'fb-sent-1'])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page-id']);
    $convo = Conversation::factory()->for($account, 'account')->create(['counterpart_remote_id' => 'psid-bob']);
    $result = app(FacebookDirectMessageConnector::class)->sendMessage($account, $convo, 'hi bob', ['access_token' => 'page-tok']);
    expect($result->remoteMessageId)->toBe('fb-sent-1');
    Http::assertSent(fn ($req) => data_get($req->data(), 'messaging_type') === 'RESPONSE');
});

test('facebook send is skipped once the messaging window has closed', function () {
    Http::fake();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page-id']);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'counterpart_remote_id' => 'psid-bob',
        'messaging_window_expires_at' => now()->subHour(),
    ]);

    $result = app(FacebookDirectMessageConnector::class)->sendMessage($account, $convo, 'hi bob', ['access_token' => 'page-tok']);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
    Http::assertNothingSent();
});

test('facebook send with media posts the attachment before the text', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::sequence()
        ->push(['message_id' => 'fb-attachment-1'])
        ->push(['message_id' => 'fb-text-1'])]);

    [$account, $convo] = facebookDmFixture();

    $result = app(FacebookDirectMessageConnector::class)
        ->sendMessage($account, $convo, 'look at this', ['access_token' => 'page-tok'], [facebookDmMedia('media/ws/pic.jpg', 'image/jpeg')]);

    expect($result->isOk())->toBeTrue()
        ->and($result->remoteMessageId)->toBe('fb-text-1');

    $sent = Http::recorded();
    expect($sent)->toHaveCount(2);

    // Text and an attachment cannot share one `message` object, so they are two calls.
    $attachment = $sent[0][0]->data();
    expect(data_get($attachment, 'message.attachment.type'))->toBe('image')
        ->and(data_get($attachment, 'message.attachment.payload.url'))->toContain('media/ws/pic.jpg')
        ->and(parse_url(data_get($attachment, 'message.attachment.payload.url'), PHP_URL_SCHEME))->not->toBeNull()
        ->and(data_get($attachment, 'message.text'))->toBeNull()
        ->and(data_get($attachment, 'messaging_type'))->toBe('RESPONSE')
        ->and(data_get($attachment, 'recipient.id'))->toBe('psid-bob');

    expect(data_get($sent[1][0]->data(), 'message.text'))->toBe('look at this')
        ->and(data_get($sent[1][0]->data(), 'messaging_type'))->toBe('RESPONSE')
        ->and(data_get($sent[1][0]->data(), 'message.attachment'))->toBeNull();
});

test('facebook send with media and no text posts only the attachment', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'fb-attachment-1'])]);

    [$account, $convo] = facebookDmFixture();

    $result = app(FacebookDirectMessageConnector::class)
        ->sendMessage($account, $convo, '', ['access_token' => 'page-tok'], [facebookDmMedia('media/ws/pic.jpg', 'image/jpeg')]);

    expect($result->isOk())->toBeTrue()
        ->and($result->remoteMessageId)->toBe('fb-attachment-1');
    expect(Http::recorded())->toHaveCount(1);
});

test('facebook never sends the text when the attachment call fails', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::sequence()
        ->push(['error' => ['code' => 100, 'message' => 'Unsupported attachment']], 400)
        ->push(['message_id' => 'fb-text-1'])]);

    [$account, $convo] = facebookDmFixture();

    $result = app(FacebookDirectMessageConnector::class)
        ->sendMessage($account, $convo, 'look at this', ['access_token' => 'page-tok'], [facebookDmMedia('media/ws/pic.jpg', 'image/jpeg')]);

    expect($result->status)->toBe(EngagementStatus::Failed);
    expect(Http::recorded())->toHaveCount(1);
});

test('facebook sends an animated gif as an image without flattening it to jpeg', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'fb-attachment-1'])]);

    [$account, $convo] = facebookDmFixture();

    $result = app(FacebookDirectMessageConnector::class)
        ->sendMessage($account, $convo, '', ['access_token' => 'page-tok'], [facebookDmMedia('media/ws/anim.gif', 'image/gif')]);

    expect($result->isOk())->toBeTrue();

    // Skips the JPEG conversion path so the animation survives.
    $sent = Http::recorded()[0][0]->data();
    expect(data_get($sent, 'message.attachment.type'))->toBe('image')
        ->and(data_get($sent, 'message.attachment.payload.url'))->toContain('media/ws/anim.gif')
        ->and(data_get($sent, 'message.attachment.payload.url'))->not->toContain('/derived/');
});

test('facebook converts an image Messenger does not accept before attaching it', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'fb-attachment-1'])]);

    [$account, $convo] = facebookDmFixture();
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/pic.webp', transparentPng());
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/ws/pic.webp', 'mime' => 'image/webp']);

    $result = app(FacebookDirectMessageConnector::class)
        ->sendMessage($account, $convo, '', ['access_token' => 'page-tok'], [$media]);

    expect($result->isOk())->toBeTrue();
    expect(data_get(Http::recorded()[0][0]->data(), 'message.attachment.payload.url'))->toContain('/derived/')
        ->and(data_get(Http::recorded()[0][0]->data(), 'message.attachment.payload.url'))->toContain('.jpg');
});

test('facebook sends a video attachment as type video', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'fb-attachment-1'])]);

    [$account, $convo] = facebookDmFixture();
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/clip.mp4', 'mp4-bytes');
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/clip.mp4']);

    $result = app(FacebookDirectMessageConnector::class)
        ->sendMessage($account, $convo, '', ['access_token' => 'page-tok'], [$media]);

    expect($result->isOk())->toBeTrue();
    expect(data_get(Http::recorded()[0][0]->data(), 'message.attachment.type'))->toBe('video');
});

test('facebook send with media is skipped once the messaging window has closed', function () {
    Http::fake();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page-id']);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'counterpart_remote_id' => 'psid-bob',
        'messaging_window_expires_at' => now()->subHour(),
    ]);

    $result = app(FacebookDirectMessageConnector::class)
        ->sendMessage($account, $convo, 'hi bob', ['access_token' => 'page-tok'], [facebookDmMedia('media/ws/pic.jpg', 'image/jpeg')]);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
    Http::assertNothingSent();
});

/** @return array{ConnectedAccount, Conversation} */
function facebookDmFixture(): array
{
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page-id']);
    $convo = Conversation::factory()->for($account, 'account')->create(['counterpart_remote_id' => 'psid-bob']);

    return [$account, $convo];
}

function facebookDmMedia(string $path, string $mime): PostMedia
{
    Storage::fake('public');
    Storage::disk('public')->put($path, 'bytes');

    return PostMedia::factory()->create(['disk' => 'public', 'path' => $path, 'mime' => $mime]);
}

test('facebook maps 190 error to auth expired', function () {
    Http::fake(['graph.facebook.com/*/conversations*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 200)]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Facebook, 'remote_account_id' => 'page-id']);
    $result = app(FacebookDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'page-tok'], null);
    expect($result->status)->toBe(EngagementStatus::AuthExpired);
});
