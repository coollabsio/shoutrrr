<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\PostingSchedule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get the bound workspace posting schedule: timezone and the recurring weekly time slots used when queueing posts.')]
class GetPostingScheduleTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        $workspaceId = $this->bindWorkspace($request);

        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $schedule = PostingSchedule::query()
            ->where('workspace_id', $workspaceId)
            ->with('slots')
            ->first();

        if ($schedule === null) {
            return Response::text(json_encode(['schedule' => null, 'slots' => []], JSON_THROW_ON_ERROR));
        }

        return Response::text(json_encode([
            'timezone' => $schedule->timezone,
            'slots' => $schedule->slots->map(fn ($slot): array => [
                'weekday' => $slot->weekday,
                'hour' => $slot->hour,
                'minute' => $slot->minute,
            ]),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
