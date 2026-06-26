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
     * Slots are wall-clock (weekday + hour + minute) in the schedule's timezone. This
     * enumerates each slot's datetime across the horizon (DST-correct: built in the
     * schedule zone, then converted to UTC), and returns the earliest that is strictly
     * after now and not already occupied by a scheduled/publishing post.
     */
    public function resolve(Workspace $workspace): ?CarbonImmutable
    {
        return $this->availableSlots($workspace)[0] ?? null;
    }

    /**
     * Resolve open future posting slots for the workspace, as UTC instants.
     *
     * @return list<CarbonImmutable>
     */
    public function availableSlots(Workspace $workspace): array
    {
        /** @var PostingSchedule|null $schedule */
        $schedule = PostingSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->with('slots')
            ->first();

        if ($schedule === null || $schedule->slots->isEmpty()) {
            return [];
        }

        $occupied = $this->occupiedInstants($workspace);
        $now = CarbonImmutable::now();
        $today = $now->setTimezone($schedule->timezone);
        $slots = [];

        for ($dayOffset = 0; $dayOffset < self::HORIZON_DAYS; $dayOffset++) {
            $date = $today->addDays($dayOffset);

            foreach ($schedule->slots as $slot) {
                // Carbon: dayOfWeek is 0=Sunday..6=Saturday — matches our weekday convention.
                if ($slot->weekday !== $date->dayOfWeek) {
                    continue;
                }

                $candidate = $date
                    ->setTime($slot->hour, $slot->minute)
                    ->setTimezone('UTC');

                if ($candidate->lessThanOrEqualTo($now)) {
                    continue;
                }

                if (isset($occupied[$candidate->toIso8601String()])) {
                    continue;
                }

                $slots[] = $candidate;
            }
        }

        usort($slots, fn (CarbonImmutable $first, CarbonImmutable $second): int => $first <=> $second);

        return $slots;
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
