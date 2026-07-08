<?php

use App\Models\PostingSchedule;

test('returns an empty schedule shape when none configured', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->getJson('/api/v1/posting-schedule')
        ->assertOk()
        ->assertExactJson(['timezone' => null, 'slots' => []]);
});

test('returns timezone and slots when a schedule exists', function () {
    [, $workspace, $token] = issuedKey();
    $schedule = PostingSchedule::factory()->for($workspace)->create(['timezone' => 'America/New_York']);
    $schedule->slots()->create(['weekday' => 1, 'hour' => 9, 'minute' => 30, 'position' => 0]);

    $this->withToken($token)->getJson('/api/v1/posting-schedule')
        ->assertOk()
        ->assertJsonPath('timezone', 'America/New_York')
        ->assertJsonPath('slots.0.weekday', 1)
        ->assertJsonPath('slots.0.hour', 9)
        ->assertJsonPath('slots.0.minute', 30);
});
