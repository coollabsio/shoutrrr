<?php

use App\Models\Conversation;

test('canReplyNow is true when no window set', function () {
    $c = Conversation::factory()->make(['messaging_window_expires_at' => null]);
    expect($c->canReplyNow())->toBeTrue();
});

test('canReplyNow is false when meta window expired', function () {
    $c = Conversation::factory()->make(['messaging_window_expires_at' => now()->subMinute()]);
    expect($c->canReplyNow())->toBeFalse();
});

test('canReplyNow is true when meta window in future', function () {
    $c = Conversation::factory()->make(['messaging_window_expires_at' => now()->addHour()]);
    expect($c->canReplyNow())->toBeTrue();
});
