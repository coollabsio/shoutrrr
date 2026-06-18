<?php

use App\Dto\Metrics\AccountMetricsResult;
use App\Dto\Metrics\PostMetricsResult;
use App\Enums\MetricsStatus;

test('ok post result carries counts and is ok', function () {
    $r = PostMetricsResult::ok(likes: 3, comments: 1, reposts: 2, impressions: 100);
    expect($r->isOk())->toBeTrue();
    expect([$r->likes, $r->comments, $r->reposts, $r->impressions])->toBe([3, 1, 2, 100]);
});

test('degraded results expose status and are not ok', function () {
    expect(PostMetricsResult::unsupported('x')->status)->toBe(MetricsStatus::Unsupported);
    expect(PostMetricsResult::rateLimited()->isOk())->toBeFalse();
    expect(AccountMetricsResult::failed('boom')->status)->toBe(MetricsStatus::Failed);
    expect(AccountMetricsResult::ok(followers: 9)->followers)->toBe(9);
});
