<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

class PostingScheduleController extends Controller
{
    public function show(): JsonResponse
    {
        $schedule = PostingSchedule::query()
            ->where('workspace_id', Context::get('workspace_id'))
            ->with('slots')
            ->first();

        // Consistent shape whether or not a schedule exists: timezone is null
        // when unconfigured, slots is always an array.
        return response()->json([
            'timezone' => $schedule?->timezone,
            'slots' => $schedule
                ? $schedule->slots->map(fn (PostingScheduleSlot $slot): array => [
                    'weekday' => $slot->weekday,
                    'hour' => $slot->hour,
                    'minute' => $slot->minute,
                ])
                : [],
        ]);
    }
}
