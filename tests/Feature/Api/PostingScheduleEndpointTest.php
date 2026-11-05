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

test('put replaces the slots, dedupes them, and keeps the timezone', function () {
    [, $workspace, $token] = issuedKey();
    $schedule = PostingSchedule::factory()->for($workspace)->create(['timezone' => 'Europe/Budapest']);
    $schedule->slots()->create(['weekday' => 0, 'hour' => 8, 'minute' => 0, 'position' => 0]);

    $this->withToken($token)->putJson('/api/v1/posting-schedule', [
        'slots' => [
            ['weekday' => 2, 'hour' => 14],
            ['weekday' => 1, 'hour' => 9, 'minute' => 30],
            ['weekday' => 2, 'hour' => 14, 'minute' => 0],
        ],
    ])->assertOk()->assertExactJson([
        'timezone' => 'Europe/Budapest',
        'slots' => [
            ['weekday' => 1, 'hour' => 9, 'minute' => 30],
            ['weekday' => 2, 'hour' => 14, 'minute' => 0],
        ],
    ]);
});

test('put creates the schedule and an empty slot list clears it', function () {
    [, $workspace, $token] = issuedKey();

    $this->withToken($token)->putJson('/api/v1/posting-schedule', [
        'timezone' => 'America/New_York',
        'slots' => [['weekday' => 3, 'hour' => 10]],
    ])->assertOk()->assertJsonPath('timezone', 'America/New_York')->assertJsonCount(1, 'slots');

    $this->withToken($token)->putJson('/api/v1/posting-schedule', ['slots' => []])
        ->assertOk()
        ->assertExactJson(['timezone' => 'America/New_York', 'slots' => []]);

    expect(PostingSchedule::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('put rejects an empty body and a bad timezone', function () {
    [, , $token] = issuedKey();

    $this->withToken($token)->putJson('/api/v1/posting-schedule', [])->assertUnprocessable();
    $this->withToken($token)->putJson('/api/v1/posting-schedule', ['timezone' => 'Mars/Olympus'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('timezone');
});

test('put is 403 for a member and for a read-only key', function () {
    [$user, $workspace, $token] = issuedKey();
    $workspace->members()->where('user_id', $user->id)->update(['role' => 'member']);

    $this->withToken($token)->putJson('/api/v1/posting-schedule', ['timezone' => 'UTC'])->assertForbidden();

    [, , $readToken] = issuedKey('read');
    $this->withToken($readToken)->putJson('/api/v1/posting-schedule', ['timezone' => 'UTC'])->assertForbidden();

    expect(PostingSchedule::query()->count())->toBe(0);
});
