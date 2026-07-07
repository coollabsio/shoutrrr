<?php

use function issuedKey;

test('a read-only key is 403 on a write endpoint', function () {
    [, , $token] = issuedKey('read');

    $this->withToken($token)->postJson('/api/v1/posts', [
        'base_text' => 'nope',
        'destination' => ['kind' => 'all'],
    ])->assertForbidden();
});

test('a read-only key can still read', function () {
    [, , $token] = issuedKey('read');

    $this->withToken($token)->getJson('/api/v1/posts')->assertOk();
});
