<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectLinks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_platforms')]
#[Description('List platforms the human can connect in this workspace, and the browser URL that starts each flow. OAuth is finished by the human, not by this tool.')]
class ListPlatformsTool extends WorkspaceTool
{
    public function handle(Request $request, AccountConnectLinks $links): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->authorize($request, 'viewAny', ConnectedAccount::class)) {
            return $denied;
        }

        return Response::text(json_encode([
            'platforms' => $links->list(),
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
