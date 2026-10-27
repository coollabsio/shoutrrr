<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\ConnectedAccountSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('disconnect_account')]
#[Description('Disconnect a connected account and delete its stored credentials. Irreversible. Requires confirm=true.')]
class DisconnectAccountTool extends WorkspaceTool
{
    public function handle(Request $request, ConnectedAccountSettings $settings): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'account_id' => ['required', 'string'],
            'confirm' => ['boolean'],
        ]);

        $account = ConnectedAccount::query()->whereKey($validated['account_id'])->first();

        if ($account === null) {
            return Response::error('No connected account with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'delete', $account)) {
            return $denied;
        }

        if ($unconfirmed = $this->requireConfirmation($request, 'This will disconnect '.$account->handle.' and delete its stored credentials.')) {
            return $unconfirmed;
        }

        $settings->disconnect($account);

        return Response::text(json_encode(['disconnected' => true, 'account_id' => $validated['account_id']], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of the connected account to disconnect.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to disconnect.'),
        ];
    }
}
