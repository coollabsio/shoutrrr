<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Services\Workspace\WorkspaceMentionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_mention')]
#[Description('Save a reusable mention to the bound workspace, or replace the handles of the saved mention with the same name. Returns the saved mention, including the `post_mention` entry for create_post.')]
class CreateMentionTool extends WorkspaceTool
{
    public function handle(Request $request, WorkspaceMentionService $mentions): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate(WorkspaceMentionService::rules());

        $mention = $mentions->save($workspaceId, $validated['name'], $validated['handles']);

        return Response::text(json_encode(['mention' => WorkspaceMentionService::present($mention)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Mention name starting with @, letters, digits, _ . - only (e.g. @acme).')->required(),
            'handles' => $schema->object()->description('Handle per platform key (x, bluesky, linkedin, facebook, instagram, threads, discord), plus optional linkedin_urn for a LinkedIn organization. Blank handles are dropped.')->required(),
        ];
    }
}
