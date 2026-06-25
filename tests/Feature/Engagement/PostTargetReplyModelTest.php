<?php

// tests/Feature/Engagement/PostTargetReplyModelTest.php
use App\Enums\Platform;
use App\Enums\ReplyStatus;
use App\Models\PostTarget;
use App\Models\PostTargetReply;
use Illuminate\Database\QueryException;

test('a reply belongs to a post target and casts its columns', function () {
    $target = PostTarget::factory()->create();

    $reply = PostTargetReply::factory()->for($target, 'target')->create([
        'platform' => Platform::Bluesky,
        'status' => ReplyStatus::Pending,
        'is_ours' => false,
    ]);

    expect($reply->target->is($target))->toBeTrue();
    expect($reply->platform)->toBe(Platform::Bluesky);
    expect($reply->status)->toBe(ReplyStatus::Pending);
    expect($reply->is_ours)->toBeFalse();
    expect($target->replies()->whereKey($reply->id)->exists())->toBeTrue();
});

test('remote_reply_id is unique per target', function () {
    $target = PostTarget::factory()->create();
    PostTargetReply::factory()->for($target, 'target')->create(['remote_reply_id' => 'r1']);

    expect(fn () => PostTargetReply::factory()->for($target, 'target')->create(['remote_reply_id' => 'r1']))
        ->toThrow(QueryException::class);
});
