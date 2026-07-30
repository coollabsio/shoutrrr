<?php

declare(strict_types=1);

use App\Enums\EngagementStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\PostMedia;
use App\Services\Messaging\Connectors\InstagramDirectMessageConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('instagram maps conversations and sets 24h window from latest inbound', function () {
    config()->set('messages.meta_window_hours', 24);
    $inbound = now()->subHours(2);
    Http::fake([
        'graph.facebook.com/*/conversations*' => Http::response(['data' => [[
            'id' => 'ig-convo-1',
            'participants' => ['data' => [['id' => 'me-igid', 'username' => 'mybiz'], ['id' => 'igsid-alice', 'username' => 'alice']]],
            'messages' => ['data' => [
                ['id' => 'igm-1', 'from' => ['id' => 'igsid-alice', 'username' => 'alice'], 'message' => 'hi there', 'created_time' => $inbound->toIso8601String()],
            ]],
        ]]]),
    ]);

    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'me-igid']);
    $result = app(InstagramDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);

    expect($result->isOk())->toBeTrue();
    $convo = $result->conversations[0];
    expect($convo->counterpartHandle)->toBe('@alice');
    expect($convo->counterpartRemoteId)->toBe('igsid-alice');
    expect($convo->messagingWindowExpiresAt)->not->toBeNull();
    expect($convo->messagingWindowExpiresAt->timestamp)->toBe($inbound->copy()->addHours(24)->timestamp);
});

test('instagram send posts recipient IGSID', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'ig-sent-1'])]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'me-igid']);
    $convo = Conversation::factory()->for($account, 'account')->create(['counterpart_remote_id' => 'igsid-alice']);
    $result = app(InstagramDirectMessageConnector::class)->sendMessage($account, $convo, 'hello', ['access_token' => 'tok']);
    expect($result->isOk())->toBeTrue();
    expect($result->remoteMessageId)->toBe('ig-sent-1');
    Http::assertSent(fn ($req) => data_get($req->data(), 'recipient.id') === 'igsid-alice');
});

test('instagram maps 190 error to auth expired', function () {
    Http::fake(['graph.facebook.com/*/conversations*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 200)]);
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'me-igid']);
    $result = app(InstagramDirectMessageConnector::class)->fetchConversations($account, ['access_token' => 'tok'], null);
    expect($result->status)->toBe(EngagementStatus::AuthExpired);
});

test('instagram send is skipped once the messaging window has closed', function () {
    Http::fake();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'me-igid']);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'counterpart_remote_id' => 'igsid-alice',
        'messaging_window_expires_at' => now()->subHour(),
    ]);

    $result = app(InstagramDirectMessageConnector::class)->sendMessage($account, $convo, 'hello', ['access_token' => 'tok']);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
    Http::assertNothingSent();
});

test('instagram send with media posts the attachment before the text', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::sequence()
        ->push(['message_id' => 'ig-attachment-1'])
        ->push(['message_id' => 'ig-text-1'])]);

    [$account, $convo] = instagramDmFixture();
    $media = instagramDmMedia('media/ws/pic.jpg', 'image/jpeg');

    $result = app(InstagramDirectMessageConnector::class)
        ->sendMessage($account, $convo, 'look at this', ['access_token' => 'tok'], [$media]);

    expect($result->isOk())->toBeTrue()
        ->and($result->remoteMessageId)->toBe('ig-text-1');

    $sent = Http::recorded();
    expect($sent)->toHaveCount(2);

    // Text and an attachment cannot share one `message` object, so they are two calls.
    $attachment = $sent[0][0]->data();
    expect(data_get($attachment, 'message.attachment.type'))->toBe('image')
        ->and(data_get($attachment, 'message.attachment.payload.url'))->toContain('media/ws/pic.jpg')
        ->and(parse_url(data_get($attachment, 'message.attachment.payload.url'), PHP_URL_SCHEME))->not->toBeNull()
        ->and(data_get($attachment, 'message.text'))->toBeNull()
        ->and(data_get($attachment, 'recipient.id'))->toBe('igsid-alice');

    expect(data_get($sent[1][0]->data(), 'message.text'))->toBe('look at this')
        ->and(data_get($sent[1][0]->data(), 'message.attachment'))->toBeNull();
});

test('instagram send with media and no text posts only the attachment', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'ig-attachment-1'])]);

    [$account, $convo] = instagramDmFixture();

    $result = app(InstagramDirectMessageConnector::class)
        ->sendMessage($account, $convo, '', ['access_token' => 'tok'], [instagramDmMedia('media/ws/pic.jpg', 'image/jpeg')]);

    expect($result->isOk())->toBeTrue()
        ->and($result->remoteMessageId)->toBe('ig-attachment-1');
    expect(Http::recorded())->toHaveCount(1);
});

test('instagram never sends the text when the attachment call fails', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::sequence()
        ->push(['error' => ['code' => 100, 'message' => 'Unsupported attachment']], 400)
        ->push(['message_id' => 'ig-text-1'])]);

    [$account, $convo] = instagramDmFixture();

    $result = app(InstagramDirectMessageConnector::class)
        ->sendMessage($account, $convo, 'look at this', ['access_token' => 'tok'], [instagramDmMedia('media/ws/pic.jpg', 'image/jpeg')]);

    expect($result->status)->toBe(EngagementStatus::Failed);
    expect(Http::recorded())->toHaveCount(1);
});

test('instagram sends an animated gif as an image without flattening it to jpeg', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'ig-attachment-1'])]);

    [$account, $convo] = instagramDmFixture();

    $result = app(InstagramDirectMessageConnector::class)
        ->sendMessage($account, $convo, '', ['access_token' => 'tok'], [instagramDmMedia('media/ws/anim.gif', 'image/gif')]);

    expect($result->isOk())->toBeTrue();

    // image/gif is outside Instagram's allowedMime; converting would kill the animation.
    $url = data_get(Http::recorded()[0][0]->data(), 'message.attachment.payload.url');
    expect(data_get(Http::recorded()[0][0]->data(), 'message.attachment.type'))->toBe('image')
        ->and($url)->toContain('media/ws/anim.gif')
        ->and($url)->not->toContain('/derived/')
        ->and($url)->not->toEndWith('.jpg');
});

test('instagram sends a video attachment as type video', function () {
    Http::fake(['graph.facebook.com/*/messages' => Http::response(['message_id' => 'ig-attachment-1'])]);

    [$account, $convo] = instagramDmFixture();
    Storage::fake('public');
    Storage::disk('public')->put('media/ws/clip.mp4', 'mp4-bytes');
    $media = PostMedia::factory()->video()->create(['disk' => 'public', 'path' => 'media/ws/clip.mp4']);

    $result = app(InstagramDirectMessageConnector::class)
        ->sendMessage($account, $convo, '', ['access_token' => 'tok'], [$media]);

    expect($result->isOk())->toBeTrue();
    expect(data_get(Http::recorded()[0][0]->data(), 'message.attachment.type'))->toBe('video');
});

test('instagram send with media is skipped once the messaging window has closed', function () {
    Http::fake();
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'me-igid']);
    $convo = Conversation::factory()->for($account, 'account')->create([
        'counterpart_remote_id' => 'igsid-alice',
        'messaging_window_expires_at' => now()->subHour(),
    ]);

    $result = app(InstagramDirectMessageConnector::class)
        ->sendMessage($account, $convo, 'hello', ['access_token' => 'tok'], [instagramDmMedia('media/ws/pic.jpg', 'image/jpeg')]);

    expect($result->status)->toBe(EngagementStatus::Unsupported);
    Http::assertNothingSent();
});

/** @return array{ConnectedAccount, Conversation} */
function instagramDmFixture(): array
{
    $account = ConnectedAccount::factory()->create(['platform' => Platform::Instagram, 'remote_account_id' => 'me-igid']);
    $convo = Conversation::factory()->for($account, 'account')->create(['counterpart_remote_id' => 'igsid-alice']);

    return [$account, $convo];
}

function instagramDmMedia(string $path, string $mime): PostMedia
{
    Storage::fake('public');
    Storage::disk('public')->put($path, 'bytes');

    return PostMedia::factory()->create(['disk' => 'public', 'path' => $path, 'mime' => $mime]);
}
