<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shares feedback=false when the feature is off', function () {
    config(['feedback.enabled' => false, 'feedback.webhook_url' => null]);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('features.feedback', false));
});

it('shares feedback=true when enabled and webhook is set', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('features.feedback', true));
});
