<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Platform;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\XAccountTierRefresher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('refresh_x_tier')]
#[Description('Re-check an X account\'s subscription tier (Free, Premium, Premium+) and update its post length and video limits. Use after the account owner upgrades or downgrades on X.')]
class RefreshXTierTool extends WorkspaceTool
{
    public function handle(Request $request, XAccountTierRefresher $tiers): Response
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

        if ($account->platform !== Platform::X) {
            return Response::error('Only X accounts have a subscription tier.');
        }

        if (! $tiers->refresh($account)) {
            return Response::error("Could not refresh {$account->handle}'s X account tier. The existing limits were kept.");
        }

        return Response::text(json_encode([
            'account_id' => $account->id,
            'handle' => $account->handle,
            'x_subscription_tier' => $account->xSubscriptionTier(),
            'x_subscription_label' => $account->xSubscriptionLabel(),
            'max_text_length' => $account->maxTextLength(),
            'max_video_duration_seconds' => $account->maxVideoDurationSeconds(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of the connected X account.')->required(),
        ];
    }
}
