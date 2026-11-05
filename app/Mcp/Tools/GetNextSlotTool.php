<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\Workspace;
use App\Services\Posts\NextSlotResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_next_slot')]
#[Description('Get the next open posting-schedule slots (ISO-8601 UTC, ascending; up to 10). slot is the one queue_post takes by default; pass any listed time to queue_post scheduled_at to pick it.')]
class GetNextSlotTool extends WorkspaceTool
{
    public function handle(Request $request, NextSlotResolver $resolver): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->authorize($request, 'viewAny', Post::class)) {
            return $denied;
        }

        $workspace = Workspace::query()->whereKey($workspaceId)->firstOrFail();

        return Response::text(json_encode($resolver->summary($workspace), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
