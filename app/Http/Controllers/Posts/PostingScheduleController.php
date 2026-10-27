<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Http\Requests\PostingSchedule\UpdatePostingScheduleRequest;
use App\Models\PostingSchedule;
use App\Models\PostingScheduleSlot;
use App\Models\User;
use App\Services\Posts\PostingScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $timezone = $schedule === null ? 'UTC' : $schedule->timezone;

        return Inertia::render('queue/index', [
            'timezone' => $timezone,
            'canManage' => $user->hasAllPermissions(['workspace.settings.manage'], $workspace->id),
            'slots' => Inertia::defer(fn (): array => $schedule === null
                ? []
                : $schedule->slots->map(fn (PostingScheduleSlot $slot): array => [
                    'weekday' => $slot->weekday,
                    'hour' => $slot->hour,
                    'minute' => $slot->minute,
                    'position' => $slot->position,
                ])->values()->all()),
        ]);
    }

    public function update(UpdatePostingScheduleRequest $request, PostingScheduleService $schedules): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);

        /** @var array{slots?: list<array{weekday: int, hour: int, minute?: int|null}>} $data */
        $data = $request->validated();

        $schedules->update($workspace->id, null, $data['slots'] ?? []);

        return back()->with('success', 'Queue saved.');
    }
}
