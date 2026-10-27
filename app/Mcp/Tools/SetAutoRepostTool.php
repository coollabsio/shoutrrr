<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_auto_repost')]
#[Description('Turn automatic repost on or off for a connected account that supports it (X, LinkedIn, Bluesky). Platforms without a native repost stay off. This does not publish immediately.')]
class SetAutoRepostTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'account_id' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
            'min_percentile' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $account = ConnectedAccount::query()->whereKey($validated['account_id'])->first();

        if ($account === null) {
            return Response::error('No connected account with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $account)) {
            return $denied;
        }

        $enabled = $account->platform->supportsRepost() && (bool) $validated['enabled'];
        $updates = array_filter([
            'enabled' => $enabled,
            'min_percentile' => isset($validated['min_percentile']) ? (float) $validated['min_percentile'] : null,
        ], fn (mixed $value): bool => $value !== null);

        DB::transaction(function () use ($account, $updates): void {
            $locked = ConnectedAccount::query()->lockForUpdate()->findOrFail($account->id);
            $autoRepost = [...($locked->capabilities['auto_repost'] ?? []), ...$updates];

            $locked->forceFill([
                'capabilities' => [...($locked->capabilities ?? []), 'auto_repost' => $autoRepost],
            ])->save();
        });

        $account->refresh();

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
            'min_percentile' => $schema->number()->description('Optional performance percentile (0-100) a post must reach before it is reposted.'),
        ];
    }
}
