<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\Publishing\LinkedInOrgResolver;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Facades\Http;

function liOrgAccount(): ConnectedAccount
{
    return ConnectedAccount::factory()->create([
        'platform' => Platform::LinkedIn->value,
        'remote_account_id' => 'person123',
    ]);
}

function liOrgResolver(): LinkedInOrgResolver
{
    $tokenManager = mock(TokenManager::class);
    $tokenManager->shouldReceive('fresh')->andReturn(['access_token' => 'test-token']);

    return new LinkedInOrgResolver($tokenManager);
}

test('resolves a vanity name to its urn and canonical localized name', function () {
    Http::fake([
        'https://api.linkedin.com/rest/organizations*' => Http::response([
            'elements' => [
                ['id' => 12345, 'localizedName' => 'Coolify', 'vanityName' => 'coolify'],
            ],
        ], 200),
    ]);

    $result = liOrgResolver()->resolve(liOrgAccount(), 'coolify');

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->urn)->toBe('urn:li:organization:12345')
        ->and($result->name)->toBe('Coolify')
        ->and($result->gated)->toBeFalse();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.linkedin.com/rest/organizations')
        && $request['q'] === 'vanityName'
        && $request['vanityName'] === 'coolify');
});

test('reports the partner wall on a 403', function () {
    Http::fake([
        'https://api.linkedin.com/rest/organizations*' => Http::response(['message' => 'Not enough permissions'], 403),
    ]);

    $result = liOrgResolver()->resolve(liOrgAccount(), 'coolify');

    expect($result->gated)->toBeTrue()
        ->and($result->isSuccessful())->toBeFalse()
        ->and($result->status)->toBe(403);
});

test('reports not found on a 404', function () {
    Http::fake([
        'https://api.linkedin.com/rest/organizations*' => Http::response([], 404),
    ]);

    $result = liOrgResolver()->resolve(liOrgAccount(), 'nope');

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->gated)->toBeFalse()
        ->and($result->status)->toBe(404);
});

test('reports not found when 200 returns no elements', function () {
    Http::fake([
        'https://api.linkedin.com/rest/organizations*' => Http::response(['elements' => []], 200),
    ]);

    $result = liOrgResolver()->resolve(liOrgAccount(), 'coolify');

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->gated)->toBeFalse();
});

test('short-circuits a direct urn reference with no http call', function () {
    Http::fake();

    $result = liOrgResolver()->resolve(liOrgAccount(), 'urn:li:organization:99');

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->urn)->toBe('urn:li:organization:99')
        ->and($result->name)->toBeNull();

    Http::assertNothingSent();
});

test('short-circuits a company url that carries a numeric id with no http call', function () {
    Http::fake();

    $result = liOrgResolver()->resolve(liOrgAccount(), 'https://www.linkedin.com/company/54321/');

    expect($result->urn)->toBe('urn:li:organization:54321');

    Http::assertNothingSent();
});
