<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

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
                'position' => $slot->position,
            ])->values()->all();
        }

        return Inertia::render('settings/posting-schedule', [
            'timezone' => $timezone,
            'slots' => $slots,
            'timezones' => timezone_identifiers_list(),
            'canManage' => $user->hasAllPermissions(['workspace.settings.manage'], $workspace->id),
        ]);
    }

    public function update(UpdatePostingScheduleRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);

        /** @var array{timezone: string, slots?: list<array{weekday: int, hour: int}>} $data */
        $data = $request->validated();
        $slots = $data['slots'] ?? [];

        DB::transaction(function () use ($workspace, $data, $slots): void {
            $schedule = PostingSchedule::query()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                ['timezone' => $data['timezone']],
            );

            $schedule->slots()->delete();

            $seen = [];
            $position = 0;
            $rows = [];

            foreach ($slots as $slot) {
                $key = $slot['weekday'].':'.$slot['hour'];

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $rows[] = [
                    'weekday' => $slot['weekday'],
                    'hour' => $slot['hour'],
                    'position' => $position++,
                ];
            }

            if ($rows !== []) {
                $schedule->slots()->createMany($rows);
            }
        });

        return back()->with('success', 'Posting schedule saved.');
    }
}
