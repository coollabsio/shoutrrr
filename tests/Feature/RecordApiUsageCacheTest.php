<?php

use App\Http\Middleware\RecordApiUsage;
use App\Models\ApiKey;

test('resolveWorkspaceId caches the token to workspace mapping', function () {
    $apiKey = ApiKey::factory()->create(['access_token_id' => 'tok-1']);

    $middleware = app(RecordApiUsage::class);

    $first = $middleware->resolveWorkspaceId('tok-1', true);
    expect($first)->toBe($apiKey->workspace_id);

    // Remove the row; a cached mapping must still resolve without a DB hit.
    ApiKey::query()->where('access_token_id', 'tok-1')->delete();

    $second = $middleware->resolveWorkspaceId('tok-1', true);
    expect($second)->toBe($apiKey->workspace_id);
});

test('resolveWorkspaceId returns null for an unknown token', function () {
    $middleware = app(RecordApiUsage::class);

    expect($middleware->resolveWorkspaceId('missing', true))->toBeNull();
});
