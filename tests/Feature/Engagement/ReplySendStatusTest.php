<?php

use App\Enums\SendStatus;
use App\Models\PostTargetReply;
use App\Support\ReplyListItem;

test('send_status casts to the enum and serializes in the list item', function () {
    $reply = PostTargetReply::factory()->create(['send_status' => SendStatus::Sending->value]);

    expect($reply->fresh()->send_status)->toBe(SendStatus::Sending);
    expect(ReplyListItem::make($reply->fresh()->load('target')))->toHaveKey('send_status', 'sending');
});

test('send_status is null by default', function () {
    $reply = PostTargetReply::factory()->create();
    expect($reply->send_status)->toBeNull();
    expect(ReplyListItem::make($reply->load('target'))['send_status'])->toBeNull();
});
