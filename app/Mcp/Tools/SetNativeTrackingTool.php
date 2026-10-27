<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sync\NativeTracking;
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
    public function handle(Request $request, NativeTracking $tracking): Response
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
            $tracking->track($workspace, $user, $account);
        } else {
            $tracking->untrack($account);
        }

        return Response::text(json_encode([
            'account_id' => $account->id,
            'native_tracking' => (bool) $validated['enabled'],
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
