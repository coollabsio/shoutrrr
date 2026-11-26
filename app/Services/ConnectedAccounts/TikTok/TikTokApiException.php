<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts\TikTok;

use RuntimeException;

final class TikTokApiException extends RuntimeException
{
    public function __construct(public readonly TikTokApiError $error)
    {
        parent::__construct($error->message());
    }
}
