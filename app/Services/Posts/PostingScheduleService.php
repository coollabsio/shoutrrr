<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use Illuminate\Support\Facades\DB;

/**
 * Posting schedule reads and writes shared by the REST API and the MCP tools.
 */
class PostingScheduleService
{
    /**
     * Update the workspace schedule. A null timezone keeps the stored one; a null
     * slot list keeps the stored slots, any list replaces them (duplicates of the
     * same weekday/hour/minute are dropped, order becomes position).
     *
     * @param  list<array{weekday: int, hour: int, minute?: int|null}>|null  $slots
     */
    public function update(string $workspaceId, ?string $timezone, ?array $slots): PostingSchedule
    {
        return DB::transaction(function () use ($workspaceId, $timezone, $slots): PostingSchedule {
            $schedule = PostingSchedule::query()->firstOrCreate(
                ['workspace_id' => $workspaceId],
                ['timezone' => $timezone ?? 'UTC'],
            );

            if ($timezone !== null && $schedule->timezone !== $timezone) {
                $schedule->forceFill(['timezone' => $timezone])->save();
            }

            if ($slots !== null) {
                $schedule->slots()->delete();
                $rows = [];

                foreach ($slots as $slot) {
                    $minute = $slot['minute'] ?? 0;
                    $rows[$slot['weekday'].':'.$slot['hour'].':'.$minute] ??= [
                        'weekday' => $slot['weekday'],
                        'hour' => $slot['hour'],
                        'minute' => $minute,
                        'position' => count($rows),
                    ];
                }

                $schedule->slots()->createMany(array_values($rows));
            }

            return $schedule->load('slots');
        });
    }

    /**
     * Same shape whether or not a schedule exists: timezone is null when
     * unconfigured, slots is always a list.
     *
     * @return array{timezone: string|null, slots: list<array{weekday: int, hour: int, minute: int}>}
     */
    public function payload(?PostingSchedule $schedule): array
    {
        return [
            'timezone' => $schedule?->timezone,
            'slots' => $schedule === null ? [] : array_values($schedule->slots->map(fn (PostingScheduleSlot $slot): array => [
                'weekday' => $slot->weekday,
                'hour' => $slot->hour,
                'minute' => $slot->minute,
            ])->all()),
        ];
    }
}
