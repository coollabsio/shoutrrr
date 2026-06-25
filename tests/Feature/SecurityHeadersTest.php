<?php

use Illuminate\Support\Facades\Vite;

test('responses carry the static security headers', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

test('responses carry a nonce-based content security policy', function () {
    $response = $this->get('/login');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain("frame-ancestors 'none'")
        ->and($csp)->toContain("object-src 'none'")
        ->and($csp)->toContain("base-uri 'self'")
        ->and($csp)->toContain("img-src 'self' data: blob: https:")
        ->and($csp)->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9+\/=]+'/")
        ->and($csp)->toContain("'strict-dynamic'");
});

test('the csp nonce is exposed to vite and differs per request', function () {
    $this->get('/login');
    $first = Vite::cspNonce();

    $this->get('/login');
    $second = Vite::cspNonce();

    expect($first)->not->toBeEmpty()
        ->and($second)->not->toBeEmpty()
        ->and($first)->not->toBe($second);
});
