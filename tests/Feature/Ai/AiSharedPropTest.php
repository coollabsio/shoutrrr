<?php

use App\Models\User;
use App\Support\InstanceSettings;

it('shares features.ai false by default and true when configured', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('features.ai', false));

    app(InstanceSettings::class)->updateAi(
        ['ai_enabled' => true, 'ai_provider' => 'anthropic', 'ai_model' => 'm'],
        'sk-test',
    );

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('features.ai', true));
});
