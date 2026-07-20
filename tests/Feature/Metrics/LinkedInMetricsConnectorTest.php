<?php

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Connectors\LinkedInMetricsConnector;
use Illuminate\Support\Facades\Http;

test('fetchPost returns unsupported for a personal account', function () {
    $account = ConnectedAccount::factory()->linkedin()->create();
    $target = PostTarget::factory()->create(['platform' => Platform::LinkedIn->value, 'remote_id' => 'urn:li:share:1']);

    $result = app(LinkedInMetricsConnector::class)->fetchPost($account, $target, ['access_token' => 't']);

    expect($result->isOk())->toBeFalse()
        ->and($result->status->value)->toBe('unsupported');
});

test('fetchPost returns share statistics for a page account', function () {
    Http::fake([
        'https://api.linkedin.com/rest/organizationalEntityShareStatistics*' => Http::response([
            'elements' => [[
                'organizationalEntity' => 'urn:li:organization:2414183',
                'share' => 'urn:li:share:1000000',
                'totalShareStatistics' => [
                    'likeCount' => 14,
                    'commentCount' => 24,
                    'shareCount' => 5,
                    'impressionCount' => 5287,
                ],
            ]],
        ]),
    ]);

    $account = ConnectedAccount::factory()->linkedinPage()->create(['remote_account_id' => '2414183']);
    $target = PostTarget::factory()->create(['platform' => Platform::LinkedIn->value, 'remote_id' => 'urn:li:share:1000000']);

    $result = app(LinkedInMetricsConnector::class)->fetchPost($account, $target, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue()
        ->and($result->likes)->toBe(14)
        ->and($result->comments)->toBe(24)
        ->and($result->reposts)->toBe(5)
        ->and($result->impressions)->toBe(5287);

    Http::assertSent(fn ($req) => str_contains($req->url(), 'organizationalEntity=urn%3Ali%3Aorganization%3A2414183')
        && str_contains($req->url(), 'shares=List'));
});

test('fetchAccount returns follower count for a page account', function () {
    Http::fake([
        'https://api.linkedin.com/rest/networkSizes/*' => Http::response(['firstDegreeSize' => 219145]),
    ]);

    $account = ConnectedAccount::factory()->linkedinPage()->create(['remote_account_id' => '2414183']);

    $result = app(LinkedInMetricsConnector::class)->fetchAccount($account, ['access_token' => 't']);

    expect($result->isOk())->toBeTrue()
        ->and($result->followers)->toBe(219145);
});

test('fetchAccount returns unsupported for a personal account', function () {
    $account = ConnectedAccount::factory()->linkedin()->create();

    $result = app(LinkedInMetricsConnector::class)->fetchAccount($account, ['access_token' => 't']);

    expect($result->status->value)->toBe('unsupported');
});
