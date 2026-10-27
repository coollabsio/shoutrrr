<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectLinks;
use App\Services\ConnectedAccounts\ConnectedAccountSettings;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectedAccountsController extends Controller
{
    public function __construct(private readonly ConnectedAccountSettings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = ConnectedAccount::query()
            ->orderBy('id', 'desc')
            ->cursorPaginate($validated['per_page'] ?? 25)
            ->through(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
            ]);

        return response()->json(CursorPage::make($paginator));
    }

    /**
     * Browser URL that re-runs the account's connect flow (expired token, revoked
     * webhook). A human must open it.
     */
    public function reconnectUrl(string $id, AccountConnectLinks $links): JsonResponse
    {
        $account = $this->findAccountOrFail($id);
        $this->authorize('update', $account);

        $link = $links->reconnect($account);

        if (! $link['available'] || $link['url'] === null) {
            abort(422, $link['reason'] ?? 'That account cannot be reconnected on this instance.');
        }

        return response()->json(['id' => $account->id, ...$link]);
    }

    /**
     * Enable or disable an account. Disabling the workspace default also clears
     * the default.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $account = $this->findAccountOrFail($id);
        $this->authorize('update', $account);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->settings->setEnabled($account, (bool) $validated['enabled']);

        return response()->json(['id' => $account->id, 'enabled' => ! $account->isDisabled()]);
    }

    public function makeDefault(string $id): JsonResponse
    {
        $account = $this->findAccountOrFail($id);
        $this->authorize('update', $account);

        $this->settings->makeDefault($account);

        return response()->json(['id' => $account->id, 'default' => true]);
    }

    /**
     * Platforms without a native repost API always store auto-repost as off.
     */
    public function autoRepost(Request $request, string $id): JsonResponse
    {
        $account = $this->findAccountOrFail($id);
        $this->authorize('update', $account);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            /** Share of the account's recent posts a post must outperform before it is reposted, from 0 to 1. Omit to keep the stored value. */
            'min_percentile' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);

        $this->settings->setAutoRepost(
            $account,
            (bool) $validated['enabled'],
            isset($validated['min_percentile']) ? (float) $validated['min_percentile'] : null,
        );

        return response()->json([
            'id' => $account->id,
            'auto_repost_enabled' => $account->autoRepostEnabled(),
            'min_percentile' => $account->autoRepostConfig()['min_percentile'],
            'supports_repost' => $account->platform->supportsRepost(),
        ]);
    }

    /**
     * Disconnect the account and delete its stored credentials. Cannot be undone.
     */
    public function destroy(string $id): JsonResponse
    {
        $account = $this->findAccountOrFail($id);
        $this->authorize('delete', $account);

        $this->settings->disconnect($account);

        return response()->json(['deleted' => true, 'id' => $account->id]);
    }

    private function findAccountOrFail(string $id): ConnectedAccount
    {
        return ConnectedAccount::query()->whereKey($id)
            ->firstOr(fn () => abort(404, 'No connected account with that id exists in this workspace.'));
    }
}
