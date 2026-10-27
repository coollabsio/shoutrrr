<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('toggle_account')]
#[Description('Enable or disable a connected account. Disabling it also clears it as the workspace default. The account stays connected.')]
class ToggleAccountTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'account_id' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        $account = ConnectedAccount::query()->whereKey($validated['account_id'])->first();

        if ($account === null) {
            return Response::error('No connected account with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $account)) {
            return $denied;
        }

        DB::transaction(function () use ($request, $account, $validated): void {
            $account->forceFill([
                'disabled_at' => $validated['enabled'] ? null : Date::now(),
            ])->save();

            if (! $validated['enabled']) {
                $workspace = $request->user()?->currentWorkspace()->first();

                if ($workspace?->default_connected_account_id === $account->id) {
                    $workspace->forceFill(['default_connected_account_id' => null])->save();
                }
            }
        });

        $account->refresh();

        return Response::text(json_encode([
            'account_id' => $account->id,
            'enabled' => ! $account->isDisabled(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of the connected account.')->required(),
            'enabled' => $schema->boolean()->description('True to enable the account, false to disable it.')->required(),
        ];
    }
}
