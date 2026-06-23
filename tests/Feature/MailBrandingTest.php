<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;

test('markdown notification emails use the Shoutrrr brand', function () {
    config([
        'app.name' => 'Shoutrrr',
        'app.url' => 'https://shoutrrr.test',
    ]);

    $user = User::factory()->unverified()->create();

    $html = (string) (new VerifyEmail)->toMail($user)->render();

    expect($html)->toContain('https://shoutrrr.test/shoutrrr.png');
    expect($html)->toContain('alt="Shoutrrr Logo"');
    expect($html)->toContain('#7dd000');
    expect($html)->not->toContain('laravel.com/img/notification-logo');
    expect($html)->not->toContain('Laravel Logo');
});
