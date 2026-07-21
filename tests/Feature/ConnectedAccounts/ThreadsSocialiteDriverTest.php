<?php

use App\Services\Auth\Socialite\ThreadsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;

beforeEach(function () {
    config()->set('services.threads.client_id', 'threads-client-id');
    config()->set('services.threads.client_secret', 'threads-client-secret');
    config()->set('services.threads.redirect', 'https://app.test/accounts/callback/threads');
});

test('the threads driver resolves to a ThreadsProvider instance', function () {
    expect(Socialite::driver('threads'))->toBeInstanceOf(ThreadsProvider::class);
});

test('the threads driver redirects to the threads authorize endpoint with the client id and scopes', function () {
    $response = Socialite::driver('threads')->stateless()->redirect();

    $location = $response->getTargetUrl();

    expect($location)->toStartWith('https://threads.net/oauth/authorize?')
        ->and($location)->toContain('client_id=threads-client-id')
        ->and($location)->toContain('scope=threads_basic%2Cthreads_content_publish%2Cthreads_manage_replies%2Cthreads_manage_insights%2Cthreads_delete');
});

test('the threads driver maps a profile fetched by token into a socialite user', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/me*' => Http::response([
            'id' => 'threads-42',
            'username' => 'ada.threads',
            'threads_profile_picture_url' => 'https://threads.example/avatar.jpg',
        ]),
    ]);

    $user = Socialite::driver('threads')->userFromToken('short-lived-token');

    expect($user->getId())->toBe('threads-42')
        ->and($user->getNickname())->toBe('ada.threads')
        ->and($user->getName())->toBe('ada.threads')
        ->and($user->getAvatar())->toBe('https://threads.example/avatar.jpg');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.threads.net/v1.0/me?fields=id%2Cusername%2Cthreads_profile_picture_url&access_token=short-lived-token');
});
