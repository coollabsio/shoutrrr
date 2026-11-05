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
    [, $workspace, $token] = issuedKey();

    $url = $this->withToken($token)->getJson('/api/v1/platforms/x/connect-url')
        ->assertOk()
        ->assertJsonPath('platform', 'x')
        ->assertJsonPath('method', 'oauth')
        ->assertJsonPath('available', true)
        ->json('url');

    expect($url)->toStartWith(route('accounts.connect', ['platform' => 'x']).'?')
        ->toContain('workspace='.$workspace->id)
        ->toContain('signature=');
});

test('connect-url passes the bluesky identifier and pds through', function () {
    [, , $token] = issuedKey();

    $url = $this->withToken($token)
        ->getJson('/api/v1/platforms/bluesky/connect-url?identifier=alice.example.com&pds_url=https://pds.example.com')
        ->assertOk()
        ->json('url');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith(route('accounts.bluesky.oauth').'?')
        ->and($query)->toMatchArray(['identifier' => 'alice.example.com', 'pds_url' => 'https://pds.example.com']);
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

    $url = $this->withToken($token)->getJson("/api/v1/connected-accounts/{$account->id}/reconnect-url")
        ->assertOk()
        ->assertJsonPath('id', $account->id)
        ->assertJsonPath('method', 'webhook')
        ->json('url');

    expect($url)->toStartWith(route('accounts.index').'?')->toContain('workspace='.$workspace->id);
});

test('reconnect-url is 404 for another workspace account', function () {
    [, , $token] = issuedKey();
    $foreign = ConnectedAccount::factory()->create();

    $this->withToken($token)->getJson("/api/v1/connected-accounts/{$foreign->id}/reconnect-url")->assertNotFound();
});

test('a read-only key cannot fetch a connect or reconnect url', function () {
    config(['services.x.client_id' => 'test-client', 'services.x.client_secret' => 'test-secret']);
    [, $workspace, $token] = issuedKey('read');
    $account = ConnectedAccount::factory()->for($workspace)->create();

    $this->withToken($token)->getJson('/api/v1/platforms/x/connect-url')->assertForbidden();
    $this->withToken($token)->getJson("/api/v1/connected-accounts/{$account->id}/reconnect-url")->assertForbidden();
});

test('platforms omits connect urls for a read-only key', function () {
    config(['services.x.client_id' => 'test-client', 'services.x.client_secret' => 'test-secret']);
    [, , $readToken] = issuedKey('read');

    $urls = $this->withToken($readToken)->getJson('/api/v1/platforms')->assertOk()->json('data.*.url');

    expect(array_filter($urls))->toBe([]);
});

test('platforms includes connect urls for a write key', function () {
    config(['services.x.client_id' => 'test-client', 'services.x.client_secret' => 'test-secret']);
    [, , $token] = issuedKey();

    $x = collect($this->withToken($token)->getJson('/api/v1/platforms')->assertOk()->json('data'))
        ->firstWhere('platform', 'x');

    expect($x['url'])->toContain('/accounts/connect/x');
});
