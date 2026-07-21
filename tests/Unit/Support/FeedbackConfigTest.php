<?php

use App\Support\FeedbackConfig;

it('is disabled by default', function () {
    config(['feedback.enabled' => false, 'feedback.webhook_url' => null]);
    expect(FeedbackConfig::enabled())->toBeFalse();
});

it('is disabled when enabled but webhook url is missing', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => null]);
    expect(FeedbackConfig::enabled())->toBeFalse();
});

it('is disabled when webhook url is set but flag is off', function () {
    config(['feedback.enabled' => false, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);
    expect(FeedbackConfig::enabled())->toBeFalse();
});

it('is enabled only when both flag and webhook url are present', function () {
    config(['feedback.enabled' => true, 'feedback.webhook_url' => 'https://discord.com/api/webhooks/1/tok']);
    expect(FeedbackConfig::enabled())->toBeTrue()
        ->and(FeedbackConfig::webhookUrl())->toBe('https://discord.com/api/webhooks/1/tok');
});
