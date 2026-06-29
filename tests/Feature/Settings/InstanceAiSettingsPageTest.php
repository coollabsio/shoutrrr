<?php

use App\Enums\InstanceRole;
use App\Models\User;
use App\Support\InstanceSettings;

function owner(): User
{
    return User::factory()->create(['instance_role' => InstanceRole::Owner->value]);
}

it('forbids non-owners from the ai settings page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/instance/ai')
        ->assertForbidden();
});

it('renders ai settings without leaking the key', function () {
    app(InstanceSettings::class)->updateAi(
        ['ai_enabled' => true, 'ai_provider' => 'anthropic', 'ai_model' => 'claude-sonnet-4-5'],
        'sk-secret',
    );

    $this->actingAs(owner())
        ->get('/settings/instance/ai')
        ->assertInertia(fn ($page) => $page
            ->component('settings/instance-ai')
            ->where('settings.ai_api_key_set', true)
            ->where('settings.ai_provider', 'anthropic')
            ->missing('settings.ai_api_key'));
});

it('owner can update ai settings and key stays encrypted', function () {
    $this->actingAs(owner())
        ->put('/settings/instance/ai', [
            'ai_enabled' => true,
            'ai_provider' => 'anthropic',
            'ai_model' => 'claude-sonnet-4-5',
            'ai_api_key' => 'sk-new',
            'ai_clear_api_key' => false,
        ])
        ->assertRedirect();

    expect(app(InstanceSettings::class)->aiApiKey())->toBe('sk-new');
});
