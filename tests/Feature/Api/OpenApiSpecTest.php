<?php

test('openapi spec is publicly reachable without a bearer token', function (): void {
    $response = $this->getJson('/api/v1/openapi.json');

    $response->assertOk();

    $spec = $response->json();

    expect($spec)->toBeArray();
    expect($spec['openapi'])->toBeString()->toStartWith('3.');
});

test('openapi spec documents the real api/v1 paths', function (): void {
    $paths = $this->getJson('/api/v1/openapi.json')->json('paths');

    expect($paths)->toBeArray()->not->toBeEmpty();

    $keys = array_keys($paths);

    expect(collect($keys)->contains(fn (string $path): bool => str_contains($path, 'posts')))->toBeTrue();
    expect(collect($keys)->contains(
        fn (string $path): bool => str_contains($path, 'connected-accounts') || str_contains($path, 'account-sets')
    ))->toBeTrue();
});

test('openapi spec declares a bearer security scheme', function (): void {
    $schemes = $this->getJson('/api/v1/openapi.json')->json('components.securitySchemes');

    expect($schemes)->toBeArray()->not->toBeEmpty();

    $hasBearerScheme = collect($schemes)->contains(
        fn (array $scheme): bool => ($scheme['type'] ?? null) === 'http' && ($scheme['scheme'] ?? null) === 'bearer'
    );

    expect($hasBearerScheme)->toBeTrue();
});

test('openapi spec documents the video upload endpoints', function (): void {
    $paths = $this->getJson('/api/v1/openapi.json')->json('paths');

    expect($paths)->toHaveKeys(['/media/video/upload-url', '/media/video']);
});

test('openapi spec documents the workspace setup endpoints', function (): void {
    $paths = $this->getJson('/api/v1/openapi.json')->json('paths');

    expect(array_keys($paths['/platforms']))->toBe(['get'])
        ->and(array_keys($paths['/platforms/{platform}/connect-url']))->toBe(['get'])
        ->and(array_keys($paths['/connected-accounts/{id}/reconnect-url']))->toBe(['get'])
        ->and(array_keys($paths['/connected-accounts/{id}']))->toEqualCanonicalizing(['patch', 'delete'])
        ->and(array_keys($paths['/connected-accounts/{id}/default']))->toBe(['post'])
        ->and(array_keys($paths['/connected-accounts/{id}/auto-repost']))->toBe(['patch'])
        ->and(array_keys($paths['/connected-accounts/{id}/native-tracking']))->toEqualCanonicalizing(['post', 'delete'])
        ->and(array_keys($paths['/sync-pipelines']))->toEqualCanonicalizing(['get', 'post'])
        ->and(array_keys($paths['/sync-pipelines/{id}']))->toEqualCanonicalizing(['patch', 'delete'])
        ->and(array_keys($paths['/posting-schedule']))->toEqualCanonicalizing(['get', 'put']);
});

test('openapi spec documents the sync pipeline and posting schedule bodies', function (): void {
    $paths = $this->getJson('/api/v1/openapi.json')->json('paths');
    $pipeline = $paths['/sync-pipelines']['post']['requestBody']['content']['application/json']['schema'];
    $schedule = $paths['/posting-schedule']['put']['requestBody']['content']['application/json']['schema'];

    expect(array_keys($pipeline['properties']))->toEqualCanonicalizing([
        'name', 'enabled', 'source_connected_account_id', 'destination_connected_account_ids', 'track_source',
    ])->and($pipeline['required'])->toEqualCanonicalizing(['name', 'source_connected_account_id', 'destination_connected_account_ids'])
        ->and(array_keys($schedule['properties']))->toEqualCanonicalizing(['timezone', 'slots']);
});

test('openapi spec documents media, segments, and mentions on the intent bodies', function (string $path): void {
    $schema = $this->getJson('/api/v1/openapi.json')
        ->json("paths.{$path}.post.requestBody.content")['application/json']['schema'];

    expect($schema['properties'])->toHaveKeys(['media_ids', 'segments', 'mentions'])
        ->and($schema['properties']['media_ids']['items']['type'])->toBe('string')
        ->and($schema['properties']['segments']['items']['type'])->toBe('string')
        ->and($schema['properties']['mentions']['items']['properties'])->toHaveKeys(['id', 'label', 'handles']);
})->with(['/intents/publish-now', '/intents/schedule-at']);
