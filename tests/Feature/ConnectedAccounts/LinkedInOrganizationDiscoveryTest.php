<?php

use App\Services\ConnectedAccounts\LinkedIn\LinkedInOrganizationDiscovery;
use App\Services\ConnectedAccounts\LinkedIn\LinkedInOrganizationDiscoveryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('discovers administered organizations and resolves their names', function () {
    Http::fake([
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response([
            'elements' => [
                ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organizationTarget' => 'urn:li:organization:2414183'],
                ['role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'organization' => 'urn:li:organization:79988552'],
            ],
            'paging' => ['start' => 0, 'count' => 10, 'links' => []],
        ]),
        'https://api.linkedin.com/rest/organizations*' => Http::response([
            'results' => [
                '2414183' => ['id' => 2414183, 'localizedName' => 'Acme Inc', 'vanityName' => 'acme'],
                '79988552' => ['id' => 79988552, 'localizedName' => 'Demo Co', 'vanityName' => 'democo'],
            ],
            'statuses' => ['2414183' => 200, '79988552' => 200],
        ]),
    ]);

    $orgs = app(LinkedInOrganizationDiscovery::class)->administeredOrganizations('tok');

    expect($orgs)->toHaveCount(2)
        ->and($orgs[0]->urn)->toBe('urn:li:organization:2414183')
        ->and($orgs[0]->id)->toBe('2414183')
        ->and($orgs[0]->name)->toBe('Acme Inc')
        ->and($orgs[0]->vanityName)->toBe('acme');

    // Rest.li 2.0 rejects an encoded `List%28...%29` with HTTP 400, so the batch
    // lookup must keep the list syntax literal on the wire.
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.linkedin.com/rest/organizations?')
        && str_contains($request->url(), 'ids=List(2414183,79988552)')
        && ! str_contains($request->url(), '%28'));
});

test('skips the organization lookup when the member administers no pages', function () {
    Http::fake(['https://api.linkedin.com/rest/organizationAcls*' => Http::response(['elements' => []])]);

    expect(app(LinkedInOrganizationDiscovery::class)->administeredOrganizations('tok'))->toBe([]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.linkedin.com/rest/organizations?'));
});

test('throws instead of reporting no pages when the acl call is forbidden', function () {
    Http::fake(['https://api.linkedin.com/rest/organizationAcls*' => Http::response([], 403)]);

    expect(fn () => app(LinkedInOrganizationDiscovery::class)->administeredOrganizations('tok'))
        ->toThrow(LinkedInOrganizationDiscoveryException::class);
});

test('flags a throttled acl call as rate limited', function () {
    Http::fake(['https://api.linkedin.com/rest/organizationAcls*' => Http::response(
        ['message' => 'Resource level throttle APPLICATION DAY limit for calls to this resource is reached.', 'status' => 429],
        429,
    )]);

    try {
        app(LinkedInOrganizationDiscovery::class)->administeredOrganizations('tok');
        $this->fail('Expected a discovery exception.');
    } catch (LinkedInOrganizationDiscoveryException $e) {
        expect($e->isRateLimited())->toBeTrue();
    }
});

test('throws when the organization lookup fails', function () {
    Http::fake([
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response([
            'elements' => [['organizationTarget' => 'urn:li:organization:2414183']],
        ]),
        'https://api.linkedin.com/rest/organizations*' => Http::response(
            ['message' => 'Invalid value type for parameter ids', 'status' => 400],
            400,
        ),
    ]);

    expect(fn () => app(LinkedInOrganizationDiscovery::class)->administeredOrganizations('tok'))
        ->toThrow(LinkedInOrganizationDiscoveryException::class);
});

test('drops organizations whose lookup was not authorized', function () {
    Http::fake([
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response([
            'elements' => [['organizationTarget' => 'urn:li:organization:2414183']],
        ]),
        'https://api.linkedin.com/rest/organizations*' => Http::response([
            'results' => [
                '2414183' => ['id' => 2414183, 'localizedName' => 'Acme Inc', 'vanityName' => 'acme'],
            ],
            'statuses' => ['2414183' => 403],
        ]),
    ]);

    expect(app(LinkedInOrganizationDiscovery::class)->administeredOrganizations('tok'))->toBe([]);
});
