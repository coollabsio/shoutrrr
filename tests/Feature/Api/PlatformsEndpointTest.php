<?php

declare(strict_types=1);

use App\Models\ConnectedAccount;

test('platforms requires a bearer token', function () {
    $this->getJson('/api/v1/platforms')->assertUnauthorized();
});

test('platforms lists every connect target', function () {
    [, , $token] = issuedKey('read');

    $platforms = $this->withToken($token)->getJson('/api/v1/platforms')
        ->assertOk()
        ->json('data.*.platform');

    expect($platforms)->toContain('x', 'bluesky', 'discord', 'linkedin-pages', 'facebook');
});

test('connect-url returns the browser start url for a configured platform', function () {
    config(['services.x.client_id' => 'test-client', 'services.x.client_secret' => 'test-secret']);
    [, , $token] = issuedKey();

    $this->withToken($token)->getJson('/api/v1/platforms/x/connect-url')
        ->assertOk()
        ->assertJsonPath('platform', 'x')
        ->assertJsonPath('method', 'oauth')
        ->assertJsonPath('available', true)
        ->assertJsonPath('url', route('accounts.connect', ['platform' => 'x']));
});

test('connect-url passes the bluesky identifier and pds through', function () {
    [, , $token] = issuedKey();

    $url = $this->withToken($token)
        ->getJson('/api/v1/platforms/bluesky/connect-url?identifier=alice.example.com&pds_url=https://pds.example.com')
        ->assertOk()
        ->json('url');

    expect($url)->toBe(route('accounts.bluesky.oauth', ['identifier' => 'alice.example.com', 'pds_url' => 'https://pds.example.com']));
});

test('connect-url is 422 for an unknown or unconfigured platform', function () {
    config(['services.x.client_id' => null, 'services.x.client_secret' => null]);
    [, , $token] = issuedKey();

    $this->withToken($token)->getJson('/api/v1/platforms/nope/connect-url')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Unknown platform. List the platforms to see the supported values.');

    $this->withToken($token)->getJson('/api/v1/platforms/x/connect-url')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'X is not configured on this instance.');
});

test('a member cannot fetch a connect url', function () {
    [$user, $workspace, $token] = issuedKey();
    $workspace->members()->where('user_id', $user->id)->update(['role' => 'member']);

    $this->withToken($token)->getJson('/api/v1/platforms/bluesky/connect-url')->assertForbidden();
});

test('reconnect-url returns the flow for an account in the workspace', function () {
    [, $workspace, $token] = issuedKey();
    $account = ConnectedAccount::factory()->for($workspace)->discord()->create();

    $this->withToken($token)->getJson("/api/v1/connected-accounts/{$account->id}/reconnect-url")
        ->assertOk()
        ->assertJsonPath('id', $account->id)
        ->assertJsonPath('method', 'webhook')
        ->assertJsonPath('url', route('accounts.index'));
});

test('reconnect-url is 404 for another workspace account', function () {
    [, , $token] = issuedKey();
    $foreign = ConnectedAccount::factory()->create();

    $this->withToken($token)->getJson("/api/v1/connected-accounts/{$foreign->id}/reconnect-url")->assertNotFound();
});
