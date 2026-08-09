<?php

test('cursor can dynamically register its desktop oauth callback', function (): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Cursor',
        'redirect_uris' => ['cursor://anysphere.cursor-deeplink/oauth'],
    ])
        ->assertCreated()
        ->assertJsonPath('redirect_uris.0', 'cursor://anysphere.cursor-deeplink/oauth');
});
