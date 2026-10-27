<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\PostingSchedule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_posting_schedule')]
#[Description('Replace the workspace posting schedule timezone and weekly queue slots. Omit slots to leave them unchanged. Pass an empty slots list to clear them. Weekday is 0 (Sunday) through 6.')]
class UpdatePostingScheduleTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        $workspaceId = $this->bindWorkspace($request);

        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->requirePermission($request, 'workspace.settings.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'timezone' => ['sometimes', 'string', 'timezone'],
            'slots' => ['sometimes', 'array'],
            'slots.*.weekday' => ['required', 'integer', 'between:0,6'],
            'slots.*.hour' => ['required', 'integer', 'between:0,23'],
            'slots.*.minute' => ['nullable', 'integer', 'between:0,59'],
        ]);

        if (! array_key_exists('timezone', $validated) && ! array_key_exists('slots', $validated)) {
            return Response::error('Provide a timezone, slots, or both.');
        }

        $schedule = DB::transaction(function () use ($workspaceId, $validated): PostingSchedule {
            $schedule = PostingSchedule::query()->firstOrCreate(
                ['workspace_id' => $workspaceId],
                ['timezone' => $validated['timezone'] ?? 'UTC'],
            );

            if (array_key_exists('timezone', $validated)) {
                $schedule->forceFill(['timezone' => $validated['timezone']])->save();
            }

            if (! array_key_exists('slots', $validated)) {
                return $schedule->load('slots');
            }

            $schedule->slots()->delete();

            $seen = [];
            $position = 0;
            $rows = [];

            foreach ($validated['slots'] as $slot) {
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

            return $schedule->load('slots');
        });

        return Response::text(json_encode([
            'timezone' => $schedule->timezone,
            'slots' => $schedule->slots->map(fn ($slot): array => [
                'weekday' => $slot->weekday,
                'hour' => $slot->hour,
                'minute' => $slot->minute,
            ])->values(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'timezone' => $schema->string()->description('IANA timezone, such as America/New_York.'),
            'slots' => $schema->array()->description('Weekly slots. Each item has weekday (0-6), hour (0-23), and optional minute (0-59). Replaces the current slots.'),
        ];
    }
}
