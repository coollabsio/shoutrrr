<?php

declare(strict_types=1);

use App\Dto\NativeRead\NativeMedia;
use App\Dto\NativeRead\NativePost;
use App\Dto\NativeRead\RecentPostsResult;
use App\Enums\MetricsStatus;
use Illuminate\Support\Facades\Date;

test('RecentPostsResult ok carries posts and newest id', function () {
    $post = new NativePost('r1', 'hello', Date::now()->toImmutable(), [new NativeMedia('https://x/y.jpg', 'image')], false, false);
    $result = RecentPostsResult::ok([$post], 'r1');

    expect($result->isOk())->toBeTrue()
        ->and($result->status)->toBe(MetricsStatus::Ok)
        ->and($result->posts)->toHaveCount(1)
        ->and($result->newestRemoteId)->toBe('r1')
        ->and($result->posts[0]->media[0]->kind)->toBe('image');
});

test('RecentPostsResult failed is not ok', function () {
    expect(RecentPostsResult::failed('nope')->isOk())->toBeFalse();
});
