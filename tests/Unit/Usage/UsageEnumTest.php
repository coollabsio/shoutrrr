<?php

use App\Enums\UsageCategory;
use App\Support\UsageOperation;

it('exposes the four usage categories with stable values', function () {
    expect(UsageCategory::Publish->value)->toBe('publish')
        ->and(UsageCategory::ExternalApi->value)->toBe('external_api')
        ->and(UsageCategory::ApiRequest->value)->toBe('api_request')
        ->and(UsageCategory::Ai->value)->toBe('ai');
});

it('exposes the built operation constants', function () {
    expect(UsageOperation::POST)->toBe('post')
        ->and(UsageOperation::TOKEN_REFRESH)->toBe('token_refresh')
        ->and(UsageOperation::MCP_REQUEST)->toBe('mcp_request');
});

it('reads the retention window from config', function () {
    expect(config('usage.retention_days'))->toBeInt();
});
