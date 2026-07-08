<?php

use function issuedKey;

test('returns null schedule when none configured', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->getJson('/api/v1/posting-schedule')
        ->assertOk()
        ->assertJsonPath('schedule', null);
});
