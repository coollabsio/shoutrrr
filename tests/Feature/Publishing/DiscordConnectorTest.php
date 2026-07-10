<?php

use App\Dto\Publishing\PublishContext;
use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Services\Publishing\Connectors\DiscordPublishConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const DISCORD_HOOK = 'https://discord.com/api/webhooks/1/tok';

/**
 * @param  list<string>  $segments
 * @param  list<PostMedia>  $media
 * @param  array<string, mixed>  $targetOverrides
 */
function discordContext(array $segments, array $media = [], array $targetOverrides = []): PublishContext
{
    $target = PostTarget::factory()->create(array_merge(['platform' => Platform::Discord->value], $targetOverrides));
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Discord->value,
        'remote_account_id' => '1',
    ]);

    return new PublishContext(
        target: $target,
        segments: $segments,
        media: $media,
        account: $account,
        credentials: ['webhook_url' => DISCORD_HOOK],
    );
}

test('discord posts a single text message with wait=true and returns the message id', function () {
    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['id' => 'm1'])]);

    $result = app(DiscordPublishConnector::class)->publish(discordContext(['hello world']));

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['m1']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'wait=true')
        && $request['content'] === 'hello world');
});

test('discord posts each segment as its own sequential message and accumulates remote_ids', function () {
    Http::fake([DISCORD_HOOK.'?wait=true' => Http::sequence()
        ->push(['id' => 'm1'])
        ->push(['id' => 'm2'])]);

    $context = discordContext(['first', 'second']);
    $result = app(DiscordPublishConnector::class)->publish($context);

    expect($result->remoteIds)->toBe(['m1', 'm2'])
        ->and($context->target->fresh()->remote_ids)->toBe(['m1', 'm2'])
        ->and($context->target->fresh()->remote_id)->toBe('m1');
});

test('discord resumes a partial thread from persisted remote_ids', function () {
    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['id' => 'm2'])]);

    $context = discordContext(['first', 'second'], [], [
        'remote_id' => 'm1',
        'remote_ids' => ['m1'],
    ]);
    $result = app(DiscordPublishConnector::class)->publish($context);

    expect($result->remoteIds)->toBe(['m1', 'm2']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['content'] === 'second');
});

test('discord attaches media to the first segment as multipart with payload_json', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['id' => 'm1'])]);

    $result = app(DiscordPublishConnector::class)->publish(discordContext(['look'], [$media]));

    expect($result->remoteIds)->toBe(['m1']);
    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($request->url(), 'wait=true')
            && str_contains($body, 'name="payload_json"')
            && str_contains($body, 'name="files[0]"');
    });
});

test('discord posts a caption-less media message', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/pic.jpg', 'jpg-bytes');
    $media = PostMedia::factory()->create(['disk' => 'public', 'path' => 'media/pic.jpg', 'mime' => 'image/jpeg']);

    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['id' => 'm1'])]);

    expect(app(DiscordPublishConnector::class)->publish(discordContext([''], [$media]))->isSuccessful())->toBeTrue();
});

test('discord rejects a message with neither text nor media', function () {
    $result = app(DiscordPublishConnector::class)->publish(discordContext(['']));

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->errorKind)->toBe(ErrorKind::Validation);
});

test('discord fails fast with no webhook url and makes no http calls', function () {
    Http::fake();
    $context = discordContext(['hi']);
    $context = new PublishContext(
        target: $context->target,
        segments: ['hi'],
        media: [],
        account: $context->account,
        credentials: [],
    );

    $result = app(DiscordPublishConnector::class)->publish($context);

    expect($result->errorKind)->toBe(ErrorKind::AuthExpired);
    Http::assertNothingSent();
});

test('discord maps 429 to RateLimited honouring retry-after', function () {
    Http::fake([DISCORD_HOOK.'?wait=true' => Http::response(['message' => 'slow'], 429, ['Retry-After' => '3'])]);

    $result = app(DiscordPublishConnector::class)->publish(discordContext(['hi']));

    expect($result->errorKind)->toBe(ErrorKind::RateLimited)
        ->and($result->retryAfter)->toBe(3);
});

test('discord delete removes each message best-effort', function () {
    Http::fake([
        DISCORD_HOOK.'/messages/m1' => Http::response([], 404),
        DISCORD_HOOK.'/messages/m2' => Http::response([], 204),
    ]);

    $target = PostTarget::factory()->create([
        'platform' => Platform::Discord->value,
        'remote_id' => 'm1',
        'remote_ids' => ['m1', 'm2'],
    ]);

    app(DiscordPublishConnector::class)->delete($target, ['webhook_url' => DISCORD_HOOK]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/m1') && $request->method() === 'DELETE');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/messages/m2') && $request->method() === 'DELETE');
});
