<?php

use App\Models\ApiKey;

test('an active key is active', function () {
    expect(ApiKey::factory()->create()->isActive())->toBeTrue();
});

test('a revoked key is not active', function () {
    expect(ApiKey::factory()->revoked()->create()->isActive())->toBeFalse();
});

test('an expired key is not active', function () {
    expect(ApiKey::factory()->expired()->create()->isActive())->toBeFalse();
});

test('a future expiry is still active', function () {
    expect(ApiKey::factory()->create(['expires_at' => now()->addYear()])->isActive())->toBeTrue();
});
