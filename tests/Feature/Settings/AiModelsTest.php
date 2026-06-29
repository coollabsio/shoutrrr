<?php

use App\Enums\InstanceRole;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

it('returns 403 for non-owner', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->postJson(route('instance-settings.ai.models'), ['provider' => 'openai', 'api_key' => 'sk-x'])
        ->assertStatus(403);
});

it('returns openai compatible models', function () {
    Http::fake([
        '*/models' => Http::response(['data' => [['id' => 'gpt-4o'], ['id' => 'gpt-4o-mini']]]),
    ]);

    $user = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);
    $this->actingAs($user);

    $response = $this->postJson(route('instance-settings.ai.models'), ['provider' => 'openai', 'api_key' => 'sk-x']);

    $response->assertStatus(200);
    $models = $response->json('models');
    expect($models)->toContain('gpt-4o')->toContain('gpt-4o-mini');
});

it('returns anthropic models', function () {
    Http::fake([
        '*/models' => Http::response(['data' => [['id' => 'claude-opus-4-5'], ['id' => 'claude-sonnet-4-5']]]),
    ]);

    $user = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);
    $this->actingAs($user);

    $response = $this->postJson(route('instance-settings.ai.models'), ['provider' => 'anthropic', 'api_key' => 'sk-ant-x']);

    $response->assertStatus(200);
    $models = $response->json('models');
    expect($models)->toContain('claude-opus-4-5')->toContain('claude-sonnet-4-5');
});

it('returns gemini models filtered and prefix stripped', function () {
    Http::fake([
        '*/models*' => Http::response([
            'models' => [
                ['name' => 'models/gemini-pro', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-embedding', 'supportedGenerationMethods' => ['embedContent']],
            ],
        ]),
    ]);

    $user = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);
    $this->actingAs($user);

    $response = $this->postJson(route('instance-settings.ai.models'), ['provider' => 'gemini', 'api_key' => 'key-x']);

    $response->assertStatus(200);
    $models = $response->json('models');
    expect($models)->toContain('gemini-pro')->not->toContain('gemini-embedding');
});

it('returns ollama models without key', function () {
    Http::fake([
        '*/api/tags' => Http::response(['models' => [['name' => 'llama3'], ['name' => 'mistral']]]),
    ]);

    $user = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);
    $this->actingAs($user);

    $response = $this->postJson(route('instance-settings.ai.models'), ['provider' => 'ollama']);

    $response->assertStatus(200);
    $models = $response->json('models');
    expect($models)->toContain('llama3')->toContain('mistral');
});

it('caches results and only calls upstream once', function () {
    Http::fake([
        '*/models' => Http::response(['data' => [['id' => 'gpt-4o']]]),
    ]);

    $user = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);
    $this->actingAs($user);

    $this->postJson(route('instance-settings.ai.models'), ['provider' => 'openai', 'api_key' => 'sk-x']);
    $this->postJson(route('instance-settings.ai.models'), ['provider' => 'openai', 'api_key' => 'sk-x']);

    Http::assertSentCount(1);
});

it('returns empty array for unsupported provider without http call', function () {
    Http::fake();

    $user = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);
    $this->actingAs($user);

    $response = $this->postJson(route('instance-settings.ai.models'), ['provider' => 'perplexity', 'api_key' => 'key-x']);

    $response->assertStatus(200);
    expect($response->json('models'))->toBe([]);
    Http::assertNothingSent();
});

it('returns 422 on provider error', function () {
    Http::fake([
        '*/models' => Http::response([], 401),
    ]);

    $user = User::factory()->create(['instance_role' => InstanceRole::Owner->value]);
    $this->actingAs($user);

    $response = $this->postJson(route('instance-settings.ai.models'), ['provider' => 'openai', 'api_key' => 'sk-bad']);

    $response->assertStatus(422);
    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});
