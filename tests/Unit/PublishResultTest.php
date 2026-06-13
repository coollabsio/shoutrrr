<?php

use App\Dto\Publishing\PublishResult;
use App\Enums\ErrorKind;

test('success result is successful and carries ids', function () {
    $result = PublishResult::success(['a', 'b']);

    expect($result->isSuccessful())->toBeTrue()
        ->and($result->remoteIds)->toBe(['a', 'b'])
        ->and($result->errorKind)->toBeNull();
});

test('failure result is not successful and carries error', function () {
    $result = PublishResult::failure(ErrorKind::RateLimited, 'slow down', 429, 'too many');

    expect($result->isSuccessful())->toBeFalse()
        ->and($result->remoteIds)->toBe([])
        ->and($result->errorKind)->toBe(ErrorKind::RateLimited)
        ->and($result->errorMessage)->toBe('slow down')
        ->and($result->httpStatus)->toBe(429)
        ->and($result->responseExcerpt)->toBe('too many')
        ->and($result->retryAfter)->toBeNull();
});

test('failure result carries a retry-after when provided', function () {
    $result = PublishResult::failure(ErrorKind::RateLimited, 'slow down', 429, 'too many', 30);

    expect($result->retryAfter)->toBe(30);
});
