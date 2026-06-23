<?php

use App\Enums\NotificationType;
use App\Support\Notifications\NotificationPreferences;

test('defaults enable in-app notifications and important email notifications', function () {
    $prefs = NotificationPreferences::defaults();

    foreach (NotificationType::cases() as $type) {
        expect($prefs->allows($type, 'in_app'))->toBeTrue();
    }

    expect($prefs->allows(NotificationType::PostPublished, 'mail'))->toBeFalse();
    expect($prefs->allows(NotificationType::WorkspaceInvite, 'mail'))->toBeTrue();
    expect($prefs->allows(NotificationType::PublishFailed, 'mail'))->toBeTrue();
    expect($prefs->allows(NotificationType::AccountNeedsAttention, 'mail'))->toBeTrue();
});

test('always-on in-app events cannot be disabled', function () {
    $prefs = NotificationPreferences::fromArray([
        'publish_failed' => ['in_app' => false, 'mail' => false],
        'account_needs_attention' => ['in_app' => false, 'mail' => true],
    ]);

    expect($prefs->allows(NotificationType::PublishFailed, 'in_app'))->toBeTrue();
    expect($prefs->allows(NotificationType::PublishFailed, 'mail'))->toBeFalse();
    expect($prefs->allows(NotificationType::AccountNeedsAttention, 'in_app'))->toBeTrue();
});

test('user can disable in-app for non-always-on events', function () {
    $prefs = NotificationPreferences::fromArray([
        'post_published' => ['in_app' => false, 'mail' => false],
    ]);

    expect($prefs->allows(NotificationType::PostPublished, 'in_app'))->toBeFalse();
    expect($prefs->channelsFor(NotificationType::PostPublished))->toBe([]);
});

test('channelsFor maps to notification channel names', function () {
    $prefs = NotificationPreferences::fromArray([
        'post_published' => ['in_app' => true, 'mail' => false],
    ]);

    expect($prefs->channelsFor(NotificationType::PostPublished))->toBe(['database']);
});
