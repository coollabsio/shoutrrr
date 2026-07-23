<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;

test('linkedInAuthorUrn builds a person urn for a personal account', function () {
    $account = ConnectedAccount::factory()->linkedin()->create(['remote_account_id' => 'PERSON1']);

    expect($account->isLinkedInOrganization())->toBeFalse()
        ->and($account->linkedInAuthorUrn())->toBe('urn:li:person:PERSON1');
});

test('linkedInAuthorUrn builds an organization urn for a page account', function () {
    $account = ConnectedAccount::factory()->linkedinPage()->create(['remote_account_id' => '2414183']);

    expect($account->isLinkedInOrganization())->toBeTrue()
        ->and($account->linkedInAuthorUrn())->toBe('urn:li:organization:2414183');
});

test('a non-linkedin account is never a linkedin organization', function () {
    $account = ConnectedAccount::factory()->create(['platform' => Platform::X->value]);

    expect($account->isLinkedInOrganization())->toBeFalse();
});
