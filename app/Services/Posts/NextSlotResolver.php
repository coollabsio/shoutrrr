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
     * Number of upcoming open slots to surface — the queue's rolling runway.
     *
     * The queue is count-based, not calendar-based: it always offers the next
     * TARGET_SLOTS free slots regardless of how sparse the posting schedule is,
     * so a twice-a-week schedule still shows a healthy runway instead of the
     * ~4 slots a fixed two-week window would yield.
     */
    public const int TARGET_SLOTS = 10;

    /**
     * Hard safety bound on how far ahead to scan, so an all-but-fully-booked or
     * extremely sparse schedule can never loop unbounded.
     */
    public const int MAX_HORIZON_DAYS = 90;

    /**
     * Resolve the earliest free future posting slot for the workspace, as a UTC instant.
     *
     * Slots are wall-clock (weekday + hour + minute) in the schedule's timezone. This
     * enumerates each slot's datetime day by day (DST-correct: built in the schedule
     * zone, then converted to UTC), and returns the earliest that is strictly after now
     * and not already occupied by a scheduled/publishing post.
     */
    public function resolve(Workspace $workspace): ?CarbonImmutable
    {
        return $this->availableSlots($workspace)[0] ?? null;
    }

    /**
     * Resolve the next open future posting slots for the workspace, as UTC instants.
     *
     * Returns at most {@see self::TARGET_SLOTS} slots, ascending, scanning forward up
     * to {@see self::MAX_HORIZON_DAYS} days.
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

        for ($dayOffset = 0; $dayOffset < self::MAX_HORIZON_DAYS; $dayOffset++) {
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

            // Days are scanned ascending, so once we have a full runway every later
            // slot is strictly later — stop early rather than scan the whole horizon.
            if (count($slots) >= self::TARGET_SLOTS) {
                break;
            }
        }

        usort($slots, fn (CarbonImmutable $first, CarbonImmutable $second): int => $first <=> $second);

        return array_slice($slots, 0, self::TARGET_SLOTS);
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
