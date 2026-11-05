<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ManagesAccountSets;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\AccountSet;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_account_set')]
#[Description('Create a named account set (a reusable group of connected accounts) in the bound workspace.')]
class CreateAccountSetTool extends WorkspaceTool
{
    use ManagesAccountSets;

    public function handle(Request $request): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->authorize($request, 'create', AccountSet::class)) {
            return $denied;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'connected_account_ids' => ['array'],
            'connected_account_ids.*' => ['string'],
        ]);

        $set = AccountSet::create(['workspace_id' => $workspaceId, 'name' => $validated['name']]);
        $set->accounts()->sync($this->scopedAccountIds($workspaceId, $validated['connected_account_ids'] ?? []));

        return Response::text(json_encode($this->view($set), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Account set name.')->required(),
            'connected_account_ids' => $schema->array()->description('Connected account ids to include.'),
        ];
    }
}
