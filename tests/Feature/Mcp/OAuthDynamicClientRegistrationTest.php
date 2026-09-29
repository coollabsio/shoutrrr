<?php

use Illuminate\Support\Facades\Log;

test('cursor can dynamically register its desktop oauth callback', function (): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Cursor',
        'redirect_uris' => ['cursor://anysphere.cursor-deeplink/oauth'],
    ])
        ->assertCreated()
        ->assertJsonPath('redirect_uris.0', 'cursor://anysphere.cursor-deeplink/oauth');
});

test('dynamic registration logs the first oauth redirect uri', function (): void {
    Log::spy();

    $this->postJson('/oauth/register', [
        'client_name' => 'Grok',
        'redirect_uris' => ['http://localhost:8787/callback'],
    ]);

    Log::shouldHaveReceived('info')
        ->once()
        ->with('MCP OAuth client registration callback', [
            'redirect_uri' => 'http://localhost:8787/callback',
        ]);
});
