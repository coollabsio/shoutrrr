<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostingSchedule;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

final class NextSlotResolver
{
    /**
     * Number of days ahead to scan for a free slot.
     */
    public const int HORIZON_DAYS = 14;

    /**
     * Resolve the earliest free future posting slot for the workspace, as a UTC instant.
     *
     * Slots are wall-clock (weekday + hour, minutes always :00) in the schedule's
     * timezone; this walks candidate hours in that zone (DST-correct) and returns the
     * first that matches a slot, is strictly after now, and is not already occupied by
     * a scheduled/publishing post in this workspace.
     */
    public function resolve(Workspace $workspace): ?CarbonImmutable
    {
        /** @var PostingSchedule|null $schedule */
        $schedule = PostingSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->with('slots')
            ->first();

        if ($schedule === null || $schedule->slots->isEmpty()) {
            return null;
        }

        $slotSet = [];
        foreach ($schedule->slots as $slot) {
            $slotSet[$slot->weekday * 24 + $slot->hour] = true;
        }

        $occupied = $this->occupiedInstants($workspace);

        $now = CarbonImmutable::now();
        // Start at the next whole hour, expressed as wall-clock in the schedule zone.
        $cursor = $now->setTimezone($schedule->timezone)
            ->startOfHour()
            ->addHour();

        $horizonHours = self::HORIZON_DAYS * 24;

        for ($i = 0; $i < $horizonHours; $i++) {
            $candidate = $cursor->addHours($i);
            // Carbon: dayOfWeek is 0=Sunday..6=Saturday — matches our weekday convention.
            $key = $candidate->dayOfWeek * 24 + $candidate->hour;

            if (! isset($slotSet[$key])) {
                continue;
            }

            $utc = $candidate->setTimezone('UTC');

            if ($utc->lessThanOrEqualTo($now)) {
                continue;
            }

            if (isset($occupied[$utc->toIso8601String()])) {
                continue;
            }

            return $utc;
        }

        return null;
    }

    /**
     * Map of occupied UTC instants (ISO-8601) for scheduled/publishing posts.
     *
     * @return array<string, true>
     */
    private function occupiedInstants(Workspace $workspace): array
    {
        $map = [];

        Post::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [PostStatus::Scheduled, PostStatus::Publishing])
            ->whereNotNull('scheduled_at')
            ->pluck('scheduled_at')
            ->each(function (CarbonImmutable $at) use (&$map): void {
                $map[$at->setTimezone('UTC')->toIso8601String()] = true;
            });

        return $map;
    }
}
