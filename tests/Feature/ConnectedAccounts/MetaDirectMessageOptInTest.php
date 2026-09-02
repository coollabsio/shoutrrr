<?php

use App\Enums\Platform;
use App\Http\Controllers\ConnectedAccounts\MetaConnectionController;

// scopes() is private; reach it the same way DirectMessageScopeOptInTest does
// for OAuthConnectionController::scopesFor().

test('meta scopes include ig and fb dm scopes when direct messages enabled', function () {
    config()->set('services.facebook.client_id', 'cid');
    config()->set('services.facebook.client_secret', 'secret');
    config()->set('messages.direct_messages_enabled', true);

    $controller = app(MetaConnectionController::class);
    $scopes = (fn () => $this->scopes())->call($controller);

    expect($scopes)
        ->toContain('instagram_manage_messages')
        ->toContain('pages_messaging');
});

test('meta scopes exclude ig and fb dm scopes when direct messages disabled', function () {
    config()->set('services.facebook.client_id', 'cid');
    config()->set('services.facebook.client_secret', 'secret');
    config()->set('messages.direct_messages_enabled', false);

    $controller = app(MetaConnectionController::class);
    $scopes = (fn () => $this->scopes())->call($controller);

    expect($scopes)
        ->not->toContain('instagram_manage_messages')
        ->not->toContain('pages_messaging');
});

test('buildAccountData sets dm_enabled true for instagram alongside page_id when enabled', function () {
    config()->set('messages.direct_messages_enabled', true);

    $data = MetaConnectionController::buildAccountData([
        'pageId' => 'PAGE1',
        'pageName' => 'My Page',
        'pageAccessToken' => 'PGT1',
        'igUserId' => 'IG1',
        'igUsername' => 'myig',
        'igAvatarUrl' => null,
    ], Platform::Instagram);

    expect($data->capabilities)->toMatchArray([
        'page_id' => 'PAGE1',
        'dm_enabled' => true,
    ]);
});

test('buildAccountData sets dm_enabled false for instagram when disabled, keeping page_id', function () {
    config()->set('messages.direct_messages_enabled', false);

    $data = MetaConnectionController::buildAccountData([
        'pageId' => 'PAGE1',
        'pageName' => 'My Page',
        'pageAccessToken' => 'PGT1',
        'igUserId' => 'IG1',
        'igUsername' => 'myig',
        'igAvatarUrl' => null,
    ], Platform::Instagram);

    expect($data->capabilities)->toMatchArray([
        'page_id' => 'PAGE1',
        'dm_enabled' => false,
    ]);
});

test('buildAccountData sets dm_enabled true for facebook when enabled', function () {
    config()->set('messages.direct_messages_enabled', true);

    $data = MetaConnectionController::buildAccountData([
        'pageId' => 'PAGE1',
        'pageName' => 'My Page',
        'pageAccessToken' => 'PGT1',
        'igUserId' => null,
        'igUsername' => null,
        'igAvatarUrl' => null,
    ], Platform::Facebook);

    expect($data->capabilities)->toMatchArray(['dm_enabled' => true]);
});

test('buildAccountData sets dm_enabled false for facebook when disabled', function () {
    config()->set('messages.direct_messages_enabled', false);

    $data = MetaConnectionController::buildAccountData([
        'pageId' => 'PAGE1',
        'pageName' => 'My Page',
        'pageAccessToken' => 'PGT1',
        'igUserId' => null,
        'igUsername' => null,
        'igAvatarUrl' => null,
    ], Platform::Facebook);

    expect($data->capabilities)->toMatchArray(['dm_enabled' => false]);
});
