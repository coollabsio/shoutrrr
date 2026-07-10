<?php

use App\Enums\Platform;
use App\Services\ConnectedAccounts\DiscordConnector;
use Illuminate\Support\Facades\Http;

const VALID_WEBHOOK = 'https://discord.com/api/webhooks/123456789/abc-DEF_token';

test('connect maps the webhook GET into ConnectedAccountData', function () {
    Http::fake([VALID_WEBHOOK => Http::response([
        'id' => '123456789',
        'name' => 'Announcements',
        'avatar' => 'abcdef0123456789',
        'channel_id' => '555',
        'guild_id' => '777',
    ])]);

    $data = app(DiscordConnector::class)->connect(VALID_WEBHOOK);

    expect($data->platform)->toBe(Platform::Discord)
        ->and($data->remoteAccountId)->toBe('123456789')
        ->and($data->handle)->toBe('Announcements')
        ->and($data->displayName)->toBe('Announcements')
        ->and($data->authMethod)->toBe('webhook')
        ->and($data->accessToken)->toBe(VALID_WEBHOOK)
        ->and($data->avatarUrl)->toBe('https://cdn.discordapp.com/avatars/123456789/abcdef0123456789.png')
        ->and($data->session)->toMatchArray(['channel_id' => '555', 'guild_id' => '777']);
});

test('connect leaves avatar null when the webhook has none', function () {
    Http::fake([VALID_WEBHOOK => Http::response([
        'id' => '123456789', 'name' => 'Bot', 'avatar' => null,
    ])]);

    expect(app(DiscordConnector::class)->connect(VALID_WEBHOOK)->avatarUrl)->toBeNull();
});

test('connect throws when Discord rejects the webhook', function () {
    Http::fake([VALID_WEBHOOK => Http::response([], 404)]);

    app(DiscordConnector::class)->connect(VALID_WEBHOOK);
})->throws(RuntimeException::class);

test('connect rejects non-Discord and malformed URLs before any request', function (string $url) {
    Http::fake();

    expect(fn () => app(DiscordConnector::class)->connect($url))
        ->toThrow(RuntimeException::class);

    Http::assertNothingSent();
})->with([
    'http scheme' => ['http://discord.com/api/webhooks/1/tok'],
    'foreign host' => ['https://evil.com/api/webhooks/1/tok'],
    'ssrf localhost' => ['https://127.0.0.1/api/webhooks/1/tok'],
    'wrong path' => ['https://discord.com/api/not-webhooks/1/tok'],
    'discord.com root' => ['https://discord.com/'],
]);

test('connect accepts the versioned api path and ptb/canary hosts', function (string $url) {
    Http::fake([$url => Http::response(['id' => '1', 'name' => 'n'])]);

    expect(app(DiscordConnector::class)->connect($url)->remoteAccountId)->toBe('1');
})->with([
    'versioned' => ['https://discord.com/api/v10/webhooks/1/tok'],
    'ptb' => ['https://ptb.discord.com/api/webhooks/1/tok'],
    'canary' => ['https://canary.discord.com/api/webhooks/1/tok'],
    'discordapp.com' => ['https://discordapp.com/api/webhooks/1/tok'],
]);
