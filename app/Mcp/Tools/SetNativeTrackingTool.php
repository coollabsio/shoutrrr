<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountNativeWatch;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('set_native_tracking')]
#[Description('Enable or disable native tracking for a connected account, so posts made outside Shoutrrr can sync. The platform must support native reads, and enabling one counts against the plan limit.')]
class SetNativeTrackingTool extends WorkspaceTool
{
    public function handle(Request $request, WorkspaceSubscriptionGate $gate): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->requirePermission($request, 'workspace.settings.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'account_id' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        $account = ConnectedAccount::query()->whereKey($validated['account_id'])->first();

        if ($account === null) {
            return Response::error('No connected account with that id exists in this workspace.');
        }

        $user = $request->user();
        $workspace = $user instanceof User ? $user->currentWorkspace()->first() : null;

        if (! $workspace instanceof Workspace) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($validated['enabled']) {
            if (! $account->platform->supportsNativeRead()) {
                return Response::error(ucfirst($account->platform->value).' does not support native tracking, so its posts can only sync when published through Shoutrrr.');
            }

            if (! $account->nativeWatch()->exists() && ! $gate->canTrackNativeAccount($workspace)) {
                $max = (int) config('subscriptions.max_native_tracked');

                return Response::error("You've reached your plan's limit of {$max} tracked accounts. Untrack one to track another.");
            }

            ConnectedAccountNativeWatch::query()->firstOrCreate(
                ['connected_account_id' => $account->id],
                ['workspace_id' => $workspace->id, 'enabled_at' => now(), 'enabled_by' => $user->id],
            );
        } else {
            ConnectedAccountNativeWatch::query()
                ->where('workspace_id', $workspace->id)
                ->where('connected_account_id', $account->id)
                ->delete();
        }

        return Response::text(json_encode([
            'account_id' => $account->id,
            'native_tracking' => $validated['enabled'] && $account->nativeWatch()->exists(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of the connected account.')->required(),
            'enabled' => $schema->boolean()->description('True to track native posts, false to stop.')->required(),
        ];
    }
}
