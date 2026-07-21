<?php

use App\Listeners\SetSentryUserContext;
use App\Models\User;
use Illuminate\Auth\Events\Authenticated;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\UserDataBag;

beforeEach(function () {
    // Isolate each test from the process-global Sentry scope.
    SentrySdk::setCurrentHub(new Hub);
});

function sentryScopeUser(): ?UserDataBag
{
    $event = Event::createEvent();

    SentrySdk::getCurrentHub()->configureScope(
        fn (Scope $scope) => $scope->applyToEvent($event),
    );

    return $event->getUser();
}

test('attaches only the authenticated user id to the Sentry scope', function () {
    config(['sentry.dsn' => 'https://public@o1.ingest.sentry.io/1']);

    $user = User::factory()->create();

    (new SetSentryUserContext)->handle(new Authenticated('web', $user));

    $scopeUser = sentryScopeUser();

    expect($scopeUser?->getId())->toBe($user->id)
        ->and($scopeUser?->getEmail())->toBeNull()
        ->and($scopeUser?->getIpAddress())->toBeNull();
});

test('does not touch the Sentry scope when no DSN is configured', function () {
    config(['sentry.dsn' => null]);

    $user = User::factory()->create();

    (new SetSentryUserContext)->handle(new Authenticated('web', $user));

    expect(sentryScopeUser())->toBeNull();
});

test('the Sentry release defaults to the VERSION file', function () {
    $version = trim((string) file_get_contents(base_path('VERSION')));

    expect(config('sentry.release'))->toBe($version)
        ->and(config('sentry-browser.release'))->toBe($version);
});

test('browser Sentry config is not injected without a frontend DSN', function () {
    config(['sentry-browser.dsn' => null]);

    $this->get('/login')->assertDontSee('window.__sentry', false);
});

test('browser Sentry config is injected when a frontend DSN is set', function () {
    config([
        'sentry-browser.dsn' => 'https://public@o1.ingest.sentry.io/1',
        'sentry-browser.environment' => 'production',
    ]);

    $response = $this->get('/login');

    $response->assertSee('window.__sentry', false);
    $response->assertSee('o1.ingest.sentry.io', false);
});
