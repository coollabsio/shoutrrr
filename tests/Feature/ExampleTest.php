<?php

use App\Models\User;

test('guests are redirected from home to login', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('authenticated users are redirected from home to dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});
