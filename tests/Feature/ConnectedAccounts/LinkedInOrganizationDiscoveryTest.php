<?php

use App\Services\ConnectedAccounts\LinkedIn\LinkedInOrganizationDiscovery;
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
});

test('returns an empty list when the acl call is forbidden', function () {
    Http::fake(['https://api.linkedin.com/rest/organizationAcls*' => Http::response([], 403)]);

    expect(app(LinkedInOrganizationDiscovery::class)->administeredOrganizations('tok'))->toBe([]);
});

test('drops organizations whose lookup was not authorized', function () {
    Http::fake([
        'https://api.linkedin.com/rest/organizationAcls*' => Http::response([
            'elements' => [['organizationTarget' => 'urn:li:organization:2414183']],
        ]),
        'https://api.linkedin.com/rest/organizations*' => Http::response([
            'results' => [],
            'statuses' => ['2414183' => 403],
        ]),
    ]);

    expect(app(LinkedInOrganizationDiscovery::class)->administeredOrganizations('tok'))->toBe([]);
});
