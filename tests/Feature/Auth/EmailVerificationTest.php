<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());

    config(['auth.email_verification.enabled' => true]);
});

test('email verification screen can be rendered', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    $this->assertTrue($user->fresh()->hasVerifiedEmail());
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email can be verified when the ESP appends tracking parameters to the link', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    // Bento (and other ESPs) append click-tracking params after the URL is
    // signed; the signature must still validate despite the extra query string.
    $trackedUrl = $verificationUrl.'&utm_source=bento&utm_medium=email&utm_campaign=broadcast&bento_uuid=b2fdac49-d470-4ab9-b942-9171b87aac77';

    $response = $this->actingAs($user)->get($trackedUrl);

    Event::assertDispatched(Verified::class);
    $this->assertTrue($user->fresh()->hasVerifiedEmail());
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified when an unrecognized query parameter is appended', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    // Only the configured tracking params are excused; tampering with any other
    // parameter must still invalidate the signature.
    $this->actingAs($user)->get($verificationUrl.'&foo=bar')->assertForbidden();

    Event::assertNotDispatched(Verified::class);
    $this->assertFalse($user->fresh()->hasVerifiedEmail());
});

test('email is not verified with invalid hash', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    $this->assertFalse($user->fresh()->hasVerifiedEmail());
});

test('email is not verified with invalid user id', function () {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => 123, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    $this->assertFalse($user->fresh()->hasVerifiedEmail());
});

test('verified user is redirected to dashboard from verification prompt', function () {
    $user = User::factory()->create();

    Event::fake();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    Event::assertNotDispatched(Verified::class);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('already verified user visiting verification link is redirected without firing event again', function () {
    $user = User::factory()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    Event::assertNotDispatched(Verified::class);
    $this->assertTrue($user->fresh()->hasVerifiedEmail());
});

test('unverified users are treated as verified when mail delivery verification is disabled', function () {
    config(['auth.email_verification.enabled' => false]);

    $user = User::factory()->unverified()->create();

    expect($user->hasVerifiedEmail())->toBeTrue();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
});
