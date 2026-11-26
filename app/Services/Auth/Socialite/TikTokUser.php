<?php

declare(strict_types=1);

namespace App\Services\Auth\Socialite;

use Laravel\Socialite\Two\User;

/**
 * A Socialite user that also carries how long TikTok says the refresh token
 * has left (`refresh_expires_in`), which Socialite itself does not keep.
 */
class TikTokUser extends User
{
    /** Seconds the refresh token has left, as TikTok reported at sign-in. */
    public ?int $refreshExpiresIn = null;

    public function setRefreshExpiresIn(mixed $seconds): static
    {
        $this->refreshExpiresIn = is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : null;

        return $this;
    }
}
