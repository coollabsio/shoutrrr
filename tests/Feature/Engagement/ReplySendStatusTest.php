<?php

use App\Enums\SendStatus;
use App\Models\PostTargetReply;
use App\Notifications\ReplyFailedNotification;
use App\Support\ReplyListItem;
use Illuminate\Notifications\AnonymousNotifiable;

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

test('a failed reply notification carries the connector reason', function () {
    $reply = PostTargetReply::factory()->create();

    $payload = (new ReplyFailedNotification($reply, 'LinkedIn comments cannot include attachments.'))
        ->toArray(new AnonymousNotifiable);

    expect($payload['body'])->toBe('LinkedIn comments cannot include attachments.');
});

test('a failed reply notification falls back to the reply text without a reason', function () {
    $reply = PostTargetReply::factory()->create(['text' => 'the original']);

    $payload = (new ReplyFailedNotification($reply))->toArray(new AnonymousNotifiable);

    expect($payload['body'])->toBe('the original');
});
