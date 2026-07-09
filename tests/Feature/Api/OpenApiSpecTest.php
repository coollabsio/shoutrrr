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
