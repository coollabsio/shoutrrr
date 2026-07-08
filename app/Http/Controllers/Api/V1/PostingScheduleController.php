<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PostingSchedule;
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

        if ($schedule === null) {
            return response()->json(['schedule' => null, 'slots' => []]);
        }

        return response()->json([
            'timezone' => $schedule->timezone,
            'slots' => $schedule->slots->map(fn ($slot): array => [
                'weekday' => $slot->weekday,
                'hour' => $slot->hour,
                'minute' => $slot->minute,
            ]),
        ]);
    }
}
