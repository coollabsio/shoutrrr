<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Services\Posts\PostingScheduleService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_posting_schedule')]
#[Description('Replace the workspace posting schedule timezone and weekly queue slots. Omit slots to leave them unchanged. Pass an empty slots list to clear them. Weekday is 0 (Sunday) through 6.')]
class UpdatePostingScheduleTool extends WorkspaceTool
{
    public function handle(Request $request, PostingScheduleService $schedules): Response
    {
        $workspaceId = $this->bindWorkspace($request);

        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->requirePermission($request, 'workspace.settings.manage')) {
            return $denied;
        }

        /** @var array{timezone?: string, slots?: list<array{weekday: int, hour: int, minute?: int|null}>} $validated */
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

        $schedule = $schedules->update($workspaceId, $validated['timezone'] ?? null, $validated['slots'] ?? null);

        return Response::text(json_encode($schedules->payload($schedule), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
