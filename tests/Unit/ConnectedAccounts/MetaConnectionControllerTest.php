<?php

use App\Enums\Platform;
use App\Http\Controllers\ConnectedAccounts\MetaConnectionController;

// Facebook is launched (Task 8); Instagram remains gated out of
// launchedMetaGraphPlatforms() until its own launch task. buildAccountData()
// is extracted as a pure, statically-callable method so the FB/IG mapping
// logic can be proven directly without going through the HTTP flow.
test('builds facebook account data from a stashed page asset', function () {
    $data = MetaConnectionController::buildAccountData([
        'pageId' => 'PAGE1',
        'pageName' => 'My Page',
        'pageAccessToken' => 'PGT1',
        'igUserId' => null,
        'igUsername' => null,
        'igAvatarUrl' => null,
    ], Platform::Facebook);

    expect($data->platform)->toBe(Platform::Facebook)
        ->and($data->remoteAccountId)->toBe('PAGE1')
        ->and($data->handle)->toBe('My Page')
        ->and($data->displayName)->toBe('My Page')
        ->and($data->avatarUrl)->toBeNull()
        ->and($data->authMethod)->toBe('oauth')
        ->and($data->accessToken)->toBe('PGT1')
        ->and($data->tokenExpiresAt)->toBeNull();
});

test('builds instagram account data from a stashed page asset with a linked ig account', function () {
    $data = MetaConnectionController::buildAccountData([
        'pageId' => 'PAGE1',
        'pageName' => 'My Page',
        'pageAccessToken' => 'PGT1',
        'igUserId' => 'IG1',
        'igUsername' => 'myig',
        'igAvatarUrl' => 'https://x/a.jpg',
    ], Platform::Instagram);

    expect($data->platform)->toBe(Platform::Instagram)
        ->and($data->remoteAccountId)->toBe('IG1')
        ->and($data->handle)->toBe('@myig')
        ->and($data->avatarUrl)->toBe('https://x/a.jpg')
        ->and($data->authMethod)->toBe('oauth')
        // IG publishing authenticates with the linked Page's token.
        ->and($data->accessToken)->toBe('PGT1')
        // The IG connector needs the Page id to address the linked Page.
        ->and($data->capabilities)->toBe(['page_id' => 'PAGE1']);
});
