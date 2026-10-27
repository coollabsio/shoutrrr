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

#[Name('reconnect_account')]
#[Description('Return the browser URL that restarts Classic reconnect for an account. The human finishes OAuth, or pastes a Discord webhook or Bluesky app password, in the browser. This tool does not submit credentials.')]
class ReconnectAccountTool extends WorkspaceTool
{
    public function handle(Request $request, AccountConnectLinks $links): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'account_id' => ['required', 'string'],
        ]);

        $account = ConnectedAccount::query()->whereKey($validated['account_id'])->first();

        if ($account === null) {
            return Response::error('No connected account with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $account)) {
            return $denied;
        }

        $link = $links->reconnect($account);

        if (! $link['available'] || $link['url'] === null) {
            return Response::error($link['reason'] ?? 'That account cannot be reconnected on this instance.');
        }

        return Response::text(json_encode([
            'account_id' => $account->id,
            ...$link,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of the connected account to reconnect.')->required(),
        ];
    }
}
