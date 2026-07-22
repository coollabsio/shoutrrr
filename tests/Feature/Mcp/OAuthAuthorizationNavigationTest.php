<?php

test('an Inertia OAuth authorization request becomes a full page navigation', function (): void {
    $path = '/oauth/authorize?response_type=code&client_id=test-client&redirect_uri=http%3A%2F%2Flocalhost%3A1455%2Fcallback&state=test-state&code_challenge=test-challenge&code_challenge_method=S256';

    $response = $this->get($path, ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');

    parse_str((string) parse_url($response->headers->get('X-Inertia-Location'), PHP_URL_QUERY), $query);

    expect($response->headers->get('X-Inertia-Location'))->toStartWith(url('/oauth/authorize').'?')
        ->and($query)->toBe([
            'client_id' => 'test-client',
            'code_challenge' => 'test-challenge',
            'code_challenge_method' => 'S256',
            'redirect_uri' => 'http://localhost:1455/callback',
            'response_type' => 'code',
            'state' => 'test-state',
        ]);
});

test('a direct OAuth authorization request continues to Passport', function (): void {
    $this->get('/oauth/authorize')
        ->assertStatus(400)
        ->assertHeaderMissing('X-Inertia-Location');
});

test('other Passport routes are not converted into full page navigations', function (): void {
    $this->post('/oauth/token', ['grant_type' => 'unsupported'], ['X-Inertia' => 'true'])
        ->assertStatus(400)
        ->assertHeaderMissing('X-Inertia-Location');
});
