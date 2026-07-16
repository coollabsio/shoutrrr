<?php

declare(strict_types=1);

namespace App\Enums;

enum EngagementStatus: string
{
    case Ok = 'ok';
    case Unsupported = 'unsupported';
    case RateLimited = 'rate_limited';
    case AuthExpired = 'auth_expired';
    case Failed = 'failed';

    public function isOk(): bool
    {
        return $this === self::Ok;
    }

    /**
     * The HTTP status a connector outcome is reported as by the engagement
     * action endpoints.
     *
     * 422 is deliberately absent and must never be used here. Inertia's
     * `useHttp` special-cases status 422 into its validation-errors path
     * (@inertiajs/react/dist/index.js:1845), parsing the body as `data.errors`
     * and firing `onError`. A connector failure returned as 422 would therefore
     * fire `onError({})` — an empty bag — silently swallowing the message and
     * skipping `onHttpException`, which is where the client rolls the optimistic
     * update back and toasts the reason. That is precisely the silent-lie bug
     * this map exists to fix, so 422 stays reserved for real validation errors.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Ok => 200,
            self::AuthExpired => 403,
            self::Unsupported => 409,
            self::RateLimited => 429,
            self::Failed => 502,
        };
    }
}
