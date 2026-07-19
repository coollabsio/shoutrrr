<?php

declare(strict_types=1);

namespace App\Support;

class FeedbackConfig
{
    public static function enabled(): bool
    {
        return (bool) config('feedback.enabled') && self::webhookUrl() !== null;
    }

    public static function webhookUrl(): ?string
    {
        $url = config('feedback.webhook_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
