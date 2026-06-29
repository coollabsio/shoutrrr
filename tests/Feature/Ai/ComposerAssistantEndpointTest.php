<?php

use App\Models\User;
use App\Support\InstanceSettings;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

function enableAi(): void
{
    app(InstanceSettings::class)->updateAi(
        ['ai_enabled' => true, 'ai_provider' => 'anthropic', 'ai_model' => 'm'],
        'sk-test',
    );
}

it('404s when ai is disabled', function () {
    $this->actingAs(User::factory()->create())
        ->post('/ai/composer/rewrite', ['text' => 'hi'])
        ->assertNotFound();
});

it('streams a rewrite as sse', function () {
    enableAi();
    Prism::fake([TextResponseFake::make()->withText('Better')])->withFakeChunkSize(100);

    $response = $this->actingAs(User::factory()->create())
        ->post('/ai/composer/rewrite', ['text' => 'hi', 'platform' => 'x', 'limit' => 280]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');
    expect($response->streamedContent())->toContain('"type":"delta"');
    expect($response->streamedContent())->toContain('Better');
});

it('emits an error frame when the model returns no text', function () {
    enableAi();
    Prism::fake([TextResponseFake::make()->withText('')]);

    $response = $this->actingAs(User::factory()->create())
        ->post('/ai/composer/rewrite', ['text' => 'hi', 'platform' => 'x', 'limit' => 280]);

    $response->assertOk();
    expect($response->streamedContent())->toContain('"type":"error"');
    expect($response->streamedContent())->not->toContain('"type":"delta"');
});

it('validates a bad preset action', function () {
    enableAi();
    $this->actingAs(User::factory()->create())
        ->postJson('/ai/composer/rewrite', ['text' => 'hi', 'action' => 'bogus'])
        ->assertStatus(422);
});
