<?php

declare(strict_types=1);

namespace App\Services\Repost;

use App\Models\PostTarget;

class EngagementScore
{
    /**
     * Weighted engagement for a published target. Comments and reposts count
     * double as stronger-intent signals. Impressions are excluded (unreliable
     * across X-basic / LinkedIn / Bluesky).
     */
    public function for(PostTarget $target): int
    {
        return (int) ($target->likes ?? 0)
            + 2 * ((int) ($target->comments ?? 0) + (int) ($target->reposts ?? 0));
    }
}
