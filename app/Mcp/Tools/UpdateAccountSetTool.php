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

#[Name('update_account_set')]
#[Description('Update the name and/or member accounts of an account set in the bound workspace.')]
class UpdateAccountSetTool extends WorkspaceTool
{
    use ManagesAccountSets;

    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'account_set_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'connected_account_ids' => ['array'],
            'connected_account_ids.*' => ['string'],
        ]);

        $set = AccountSet::query()->whereKey($validated['account_set_id'])->first();
        if ($set === null) {
            return Response::error('No account set with that id exists in this workspace.');
        }

        $set->update(['name' => $validated['name']]);
        $set->accounts()->sync($this->scopedAccountIds($set->workspace_id, $validated['connected_account_ids'] ?? []));

        return Response::text(json_encode($this->view($set->fresh()), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_set_id' => $schema->string()->description('Id of the account set to update.')->required(),
            'name' => $schema->string()->description('New account set name.')->required(),
            'connected_account_ids' => $schema->array()->description('Connected account ids to include (replaces existing members).'),
        ];
    }
}
