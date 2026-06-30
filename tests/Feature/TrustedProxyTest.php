<?php

use Illuminate\Http\Middleware\TrustProxies;

afterEach(function (): void {
    TrustProxies::flushState();
});

test('honors X-Forwarded-Proto from a trusted proxy, generating https redirects', function (): void {
    TrustProxies::at('*');

    $response = $this->get('/', ['X-Forwarded-Proto' => 'https']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://');
});

test('ignores X-Forwarded-Proto when no proxy is trusted', function (): void {
    $response = $this->get('/', ['X-Forwarded-Proto' => 'https']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('http://');
});
