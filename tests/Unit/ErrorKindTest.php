<?php

use App\Enums\ErrorKind;

test('retryable kinds report retryable', function () {
    expect(ErrorKind::RateLimited->isRetryable())->toBeTrue()
        ->and(ErrorKind::Network->isRetryable())->toBeTrue()
        ->and(ErrorKind::ServerError->isRetryable())->toBeTrue();
});

test('terminal kinds report not retryable', function () {
    expect(ErrorKind::AuthExpired->isRetryable())->toBeFalse()
        ->and(ErrorKind::Validation->isRetryable())->toBeFalse()
        ->and(ErrorKind::DuplicateContent->isRetryable())->toBeFalse()
        ->and(ErrorKind::Unsupported->isRetryable())->toBeFalse()
        ->and(ErrorKind::Unknown->isRetryable())->toBeFalse();
});

test('cases carry the wire values', function () {
    expect(ErrorKind::RateLimited->value)->toBe('rate_limited')
        ->and(ErrorKind::AuthExpired->value)->toBe('auth_expired')
        ->and(ErrorKind::ServerError->value)->toBe('server_error');
});
