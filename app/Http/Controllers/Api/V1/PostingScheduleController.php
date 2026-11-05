<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PostingSchedule;
use App\Models\User;
use App\Services\Posts\PostingScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;

class PostingScheduleController extends Controller
{
    public function __construct(private readonly PostingScheduleService $schedules) {}

    public function show(): JsonResponse
    {
        $schedule = PostingSchedule::query()
            ->where('workspace_id', Context::get('workspace_id'))
            ->with('slots')
            ->first();

        return response()->json($this->schedules->payload($schedule));
    }

    /**
     * Set the timezone, the weekly slots, or both. Sent slots replace every
     * stored slot; an empty list clears the queue. Omitted fields keep their
     * stored value. Needs the workspace.settings.manage permission.
     */
    public function update(Request $request): JsonResponse
    {
        $workspaceId = (string) Context::get('workspace_id');

        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAllPermissions(['workspace.settings.manage'], $workspaceId), 403, 'This action is unauthorized.');

        $validated = $request->validate([
            /** IANA timezone, such as America/New_York. */
            'timezone' => ['sometimes', 'string', 'timezone'],
            'slots' => ['sometimes', 'array'],
            'slots.*.weekday' => ['required', 'integer', 'between:0,6'],
            'slots.*.hour' => ['required', 'integer', 'between:0,23'],
            'slots.*.minute' => ['nullable', 'integer', 'between:0,59'],
        ]);

        if (! array_key_exists('timezone', $validated) && ! array_key_exists('slots', $validated)) {
            throw ValidationException::withMessages(['slots' => 'Provide a timezone, slots, or both.']);
        }

        /** @var list<array{weekday: int, hour: int, minute?: int|null}>|null $slots */
        $slots = $validated['slots'] ?? null;
        $schedule = $this->schedules->update($workspaceId, $validated['timezone'] ?? null, $slots);

        return response()->json($this->schedules->payload($schedule));
    }
}
