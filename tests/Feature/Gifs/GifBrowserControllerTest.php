<?php

use App\Http\Controllers\Gifs\GifBrowserController;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function gifPayload(): array
{
    return [
        'result' => true,
        'data' => [
            'has_next' => false,
            'data' => [[
                'id' => 1,
                'slug' => 'yay-1',
                'title' => 'Yay',
                'file' => ['sm' => ['gif' => ['url' => 'https://cdn.klipy.com/sm.gif', 'width' => 120, 'height' => 90, 'size' => 1000]]],
            ]],
        ],
    ];
}

beforeEach(function (): void {
    config()->set('services.klipy.key', 'test-key');
});

test('404s when no api key is configured', function () {
    config()->set('services.klipy.key', null);

    $this->actingAs(User::factory()->withWorkspace()->create())
        ->getJson('/gifs/gif')
        ->assertNotFound();
});

test('requires authentication', function () {
    $this->getJson('/gifs/gif')->assertUnauthorized();
});

test('returns normalized items', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response(gifPayload())]);

    $this->actingAs(User::factory()->withWorkspace()->create())
        ->getJson('/gifs/gif?q=yay')
        ->assertOk()
        ->assertJsonPath('has_next', false)
        ->assertJsonPath('items.0.slug', 'yay-1')
        ->assertJsonPath('items.0.catalog', 'gif')
        ->assertJsonPath('items.0.preview.url', 'https://cdn.klipy.com/sm.gif');
});

test('rejects an unknown catalog', function () {
    $this->actingAs(User::factory()->withWorkspace()->create())
        ->getJson('/gifs/memes')
        ->assertNotFound();
});

test('caches repeat queries so klipy is hit once', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response(gifPayload())]);
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)->getJson('/gifs/gif?q=yay')->assertOk();
    $this->actingAs($user)->getJson('/gifs/gif?q=yay')->assertOk();

    Http::assertSentCount(1);
});

test('returns 502 when klipy is down', function () {
    Http::fake(['https://api.klipy.com/*' => Http::response('boom', 500)]);

    $this->actingAs(User::factory()->withWorkspace()->create())
        ->getJson('/gifs/gif?q=down')
        ->assertStatus(502);
});

test('the customer id is derived, stable, and not the raw user id', function () {
    $user = User::factory()->withWorkspace()->create();

    $first = GifBrowserController::customerId($user);
    $second = GifBrowserController::customerId($user);
    $other = GifBrowserController::customerId(User::factory()->withWorkspace()->create());

    expect($first)->toBe($second)
        ->and($first)->not->toBe($other)
        ->and($first)->not->toContain((string) $user->id);
});

test('shares the gifs_enabled flag with the frontend', function () {
    $this->actingAs(User::factory()->withWorkspace()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('shell.gifs_enabled', true));
});
