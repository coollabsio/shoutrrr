<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostingSchedule\UpdatePostingScheduleRequest;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PostingScheduleController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);

        $schedule = PostingSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->with('slots')
            ->first();

        if ($schedule === null) {
            $timezone = 'UTC';
            $slots = [];
        } else {
            $timezone = $schedule->timezone;
            $slots = $schedule->slots->map(fn (PostingScheduleSlot $slot): array => [
                'weekday' => $slot->weekday,
                'hour' => $slot->hour,
                'minute' => $slot->minute,
                'position' => $slot->position,
            ])->values()->all();
        }

        return Inertia::render('queue/index', [
            'timezone' => $timezone,
            'slots' => $slots,
            'canManage' => $user->hasAllPermissions(['workspace.settings.manage'], $workspace->id),
        ]);
    }

    public function update(UpdatePostingScheduleRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);

        /** @var array{slots?: list<array{weekday: int, hour: int, minute?: int}>} $data */
        $data = $request->validated();
        $slots = $data['slots'] ?? [];

        DB::transaction(function () use ($workspace, $slots): void {
            $schedule = PostingSchedule::query()->firstOrCreate(
                ['workspace_id' => $workspace->id],
            );

            $schedule->slots()->delete();

            $seen = [];
            $position = 0;
            $rows = [];

            foreach ($slots as $slot) {
                $minute = $slot['minute'] ?? 0;
                $key = $slot['weekday'].':'.$slot['hour'].':'.$minute;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rows[] = [
                    'weekday' => $slot['weekday'],
                    'hour' => $slot['hour'],
                    'minute' => $minute,
                    'position' => $position++,
                ];
            }

            if ($rows !== []) {
                $schedule->slots()->createMany($rows);
            }
        });

        return back()->with('success', 'Queue saved.');
    }
}
