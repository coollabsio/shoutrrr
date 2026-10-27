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

#[Name('set_auto_repost')]
#[Description('Turn automatic repost on or off for a connected account that supports it (X, LinkedIn, Bluesky). Platforms without a native repost stay off. This does not publish immediately.')]
class SetAutoRepostTool extends WorkspaceTool
{
    public function handle(Request $request, ConnectedAccountSettings $settings): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'account_id' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
            'min_percentile' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $account = ConnectedAccount::query()->whereKey($validated['account_id'])->first();

        if ($account === null) {
            return Response::error('No connected account with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $account)) {
            return $denied;
        }

        $settings->setAutoRepost(
            $account,
            (bool) $validated['enabled'],
            isset($validated['min_percentile']) ? (float) $validated['min_percentile'] : null,
        );

        return Response::text(json_encode([
            'account_id' => $account->id,
            'auto_repost_enabled' => $account->autoRepostEnabled(),
            'supports_repost' => $account->platform->supportsRepost(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of the connected account.')->required(),
            'enabled' => $schema->boolean()->description('True to allow automatic reposts, false to turn them off.')->required(),
            'min_percentile' => $schema->number()->description('Optional performance percentile as a fraction from 0 to 1 (0.75 means top 25%) a post must reach before it is reposted. Omit to keep the stored value.'),
        ];
    }
}
