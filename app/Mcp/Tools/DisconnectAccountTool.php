<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Models\SyncPipeline;
use App\Services\ConnectedAccounts\ConnectedAccountSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('disconnect_account')]
#[Description('Disconnect a connected account and delete its stored credentials, the sync pipelines that post from it, and its targets on posts (including scheduled ones). Irreversible. Requires confirm=true.')]
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

        if ($unconfirmed = $this->requireConfirmation($request, $this->consequence($account))) {
            return $unconfirmed;
        }

        $settings->disconnect($account);

        return Response::text(json_encode(['disconnected' => true, 'account_id' => $validated['account_id']], JSON_THROW_ON_ERROR));
    }

    /**
     * Spell out what the database cascades delete along with the account, so the
     * human confirms with the real blast radius in view.
     */
    private function consequence(ConnectedAccount $account): string
    {
        $sourcePipelines = SyncPipeline::query()->where('source_connected_account_id', $account->id)->count();
        $destinationPipelines = SyncPipeline::query()
            ->where('source_connected_account_id', '!=', $account->id)
            ->whereHas('destinations', fn (Builder $query) => $query->whereKey($account->id))
            ->count();
        $scheduledPosts = PostTarget::query()
            ->where('connected_account_id', $account->id)
            ->whereHas('post', fn (Builder $query) => $query->where('status', PostStatus::Scheduled))
            ->distinct()
            ->count('post_id');
        $otherPosts = PostTarget::query()
            ->where('connected_account_id', $account->id)
            ->whereDoesntHave('post', fn (Builder $query) => $query->where('status', PostStatus::Scheduled))
            ->distinct()
            ->count('post_id');

        return 'This will disconnect '.$account->handle.' and delete its stored credentials.'
            ." It also deletes {$sourcePipelines} sync pipeline(s) that post from it,"
            ." removes it as a destination from {$destinationPipelines} sync pipeline(s),"
            ." and removes it from {$scheduledPosts} scheduled post(s) and {$otherPosts} other post(s).";
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
