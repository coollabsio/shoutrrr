<?php

use App\Services\ConnectedAccounts\Meta\MetaAssetEnumerator;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.facebook.graph_version', 'v25.0');
});

test('exchanges a short lived token for a long lived one', function () {
    Http::fake([
        '*/v25.0/oauth/access_token*' => Http::response([
            'access_token' => 'LONG_USER_TOKEN',
            'token_type' => 'bearer',
            'expires_in' => 5183944,
        ]),
    ]);

    $result = app(MetaAssetEnumerator::class)->exchangeForLongLivedToken('SHORT_TOKEN');

    expect($result['token'])->toBe('LONG_USER_TOKEN')
        ->and($result['expiresAt'])->not->toBeNull()
        ->and($result['expiresAt']->diffInSeconds(now()->addSeconds(5183944), true))->toBeLessThan(5);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v25.0/oauth/access_token')
            && $request['grant_type'] === 'fb_exchange_token'
            && $request['fb_exchange_token'] === 'SHORT_TOKEN';
    });
});

test('lists pages with a linked instagram account', function () {
    Http::fake(['*/me/accounts*' => Http::response(['data' => [[
        'id' => 'PAGE1', 'name' => 'My Page', 'access_token' => 'PGT1',
        'instagram_business_account' => ['id' => 'IG1', 'username' => 'myig', 'profile_picture_url' => 'https://x/a.jpg'],
    ]]])]);

    $assets = app(MetaAssetEnumerator::class)->listPages('LONG_USER_TOKEN');

    expect($assets)->toHaveCount(1)
        ->and($assets[0]->pageId)->toBe('PAGE1')
        ->and($assets[0]->pageName)->toBe('My Page')
        ->and($assets[0]->pageAccessToken)->toBe('PGT1')
        ->and($assets[0]->igUserId)->toBe('IG1')
        ->and($assets[0]->igUsername)->toBe('myig')
        ->and($assets[0]->igAvatarUrl)->toBe('https://x/a.jpg');
});

test('lists a page with no linked instagram account', function () {
    Http::fake(['*/me/accounts*' => Http::response(['data' => [[
        'id' => 'PAGE2', 'name' => 'No IG Page', 'access_token' => 'PGT2',
    ]]])]);

    $assets = app(MetaAssetEnumerator::class)->listPages('LONG_USER_TOKEN');

    expect($assets)->toHaveCount(1)
        ->and($assets[0]->pageId)->toBe('PAGE2')
        ->and($assets[0]->igUserId)->toBeNull()
        ->and($assets[0]->igUsername)->toBeNull()
        ->and($assets[0]->igAvatarUrl)->toBeNull();
});

test('follows pagination to collect all pages', function () {
    Http::fake([
        '*/me/accounts*' => Http::sequence()
            ->push([
                'data' => [[
                    'id' => 'PAGE1', 'name' => 'First Page', 'access_token' => 'PGT1',
                ]],
                'paging' => [
                    'cursors' => ['after' => 'CURSOR1'],
                    'next' => 'https://graph.facebook.com/v25.0/me/accounts?after=CURSOR1',
                ],
            ])
            ->push([
                'data' => [[
                    'id' => 'PAGE2', 'name' => 'Second Page', 'access_token' => 'PGT2',
                ]],
                'paging' => [
                    'cursors' => ['after' => 'CURSOR2'],
                ],
            ]),
    ]);

    $assets = app(MetaAssetEnumerator::class)->listPages('LONG_USER_TOKEN');

    expect($assets)->toHaveCount(2)
        ->and($assets[0]->pageId)->toBe('PAGE1')
        ->and($assets[1]->pageId)->toBe('PAGE2');

    Http::assertSentCount(2);
});
