<?php

use App\Enums\GoogleBusinessProfileReadinessIssueCode;
use App\Exceptions\GoogleBusinessProfileDiscoveryException;
use App\Services\ConnectedAccounts\GoogleBusinessProfile\GoogleBusinessProfileLocationDiscovery;
use Illuminate\Support\Facades\Http;

test('discovers locations across account and location pages with the required read mask', function () {
    Http::fake([
        'https://mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::sequence()
            ->push(['accounts' => [['name' => 'accounts/one']], 'nextPageToken' => 'account-page-2'])
            ->push(['accounts' => [['name' => 'accounts/two']]]),
        'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/one/locations*' => Http::sequence()
            ->push(['locations' => [['name' => 'locations/one', 'title' => 'First']], 'nextPageToken' => 'location-page-2'])
            ->push(['locations' => [['name' => 'locations/two', 'title' => 'Second']]]),
        'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/two/locations*' => Http::response(['locations' => []]),
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/one/localPosts*' => Http::response(['localPosts' => []]),
        'https://mybusiness.googleapis.com/v4/accounts/one/locations/two/localPosts*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'Caller cannot manage Local Posts for this location.']], 403),
    ]);

    $result = app(GoogleBusinessProfileLocationDiscovery::class)->discover('access-token');

    expect($result->locations)->toHaveCount(2)
        ->and($result->locations[0]->key)->toBe('accounts/one/locations/one')
        ->and($result->locations[0]->canOperateLocalPost)->toBeTrue()
        ->and($result->locations[1]->canOperateLocalPost)->toBeFalse()
        ->and($result->locations[1]->readinessIssues[0]->code)->toBe(GoogleBusinessProfileReadinessIssueCode::PermissionDenied)
        ->and($result->locations[1]->readinessIssues[0]->message)->toContain('read-only Local Posts check');

    Http::assertSent(fn ($request) => parse_url($request->url(), PHP_URL_HOST) === 'mybusinessbusinessinformation.googleapis.com'
        && str_contains($request->url(), 'accounts/one/locations')
        && $request['readMask'] === GoogleBusinessProfileLocationDiscovery::LOCATION_READ_MASK
        && $request['pageSize'] === 100);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'accounts/one/locations/one/localPosts')
        && $request['pageSize'] === 1);
});

test('reports an API-disabled Google error without exposing tokens', function () {
    Http::fake([
        'https://mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response([
            'error' => [
                'status' => 'PERMISSION_DENIED',
                'message' => 'Google Business Profile API has not been used in project 123 before or it is disabled.',
                'details' => [[
                    'reason' => 'SERVICE_DISABLED',
                    'metadata' => ['service' => 'mybusinessaccountmanagement.googleapis.com'],
                ]],
            ],
        ], 403),
    ]);

    try {
        app(GoogleBusinessProfileLocationDiscovery::class)->discover('access-token');
        $this->fail('Expected discovery to fail.');
    } catch (GoogleBusinessProfileDiscoveryException $exception) {
        expect($exception->issue->code)->toBe(GoogleBusinessProfileReadinessIssueCode::ApiDisabled)
            ->and($exception->issue->service)->toBe('mybusinessaccountmanagement.googleapis.com');
    }
});

test('reports zero locations when discovery is otherwise successful', function () {
    Http::fake([
        'https://mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response(['accounts' => []]),
    ]);

    $result = app(GoogleBusinessProfileLocationDiscovery::class)->discover('access-token');

    expect($result->locations)->toBeEmpty()
        ->and($result->issues[0]->code)->toBe(GoogleBusinessProfileReadinessIssueCode::ZeroLocations);
});
