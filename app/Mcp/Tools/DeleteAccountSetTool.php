<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\AccountSet;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_account_set')]
#[Description('Delete an account set from the bound workspace. Does not affect posts that used the set.')]
class DeleteAccountSetTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'account_set_id' => ['required', 'string'],
        ]);

        $set = AccountSet::query()->whereKey($validated['account_set_id'])->first();
        if ($set === null) {
            return Response::error('No account set with that id exists in this workspace.');
        }

        $id = $set->id;
        $set->delete();

        return Response::text(json_encode(['deleted' => true, 'id' => $id], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_set_id' => $schema->string()->description('Id of the account set to delete.')->required(),
        ];
    }
}
