<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\ConnectedAccounts\BlueskyConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ConnectedAccountController extends Controller
{
    public function __construct(
        private readonly BlueskyConnector $connector,
        private readonly AccountConnectionService $connections,
    ) {}

    public function index(Request $request): Response
    {
        $request->user()->can('viewAny', ConnectedAccount::class) ?: abort(403);
        $defaultAccountId = $request->user()->currentWorkspace()->value('default_connected_account_id');

        $accounts = ConnectedAccount::query()
            ->with(['connectedBy:id,name', 'secret:connected_account_id,session'])
            ->latest()
            ->get()
            ->sortByDesc(fn (ConnectedAccount $account): bool => $account->id === $defaultAccountId)
            ->map(fn (ConnectedAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'platform_label' => $account->platform->label(),
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'auth_method' => $account->auth_method,
                'connected_by' => $account->connectedBy?->name,
                'token_expires_at' => $account->token_expires_at?->toIso8601String(),
                'max_text_length' => $account->maxTextLength(),
                'x_premium' => $account->hasXPremium(),
                'is_default' => $account->id === $defaultAccountId,
                'pds_url' => $this->customPdsUrl($account),
            ])
            ->values()
            ->all();

        return Inertia::render('accounts/index', [
            'accounts' => $accounts,
            'capabilities' => Platform::capabilities(),
            'canManage' => $request->user()->can('create', ConnectedAccount::class),
        ]);
    }

    /**
     * The saved PDS for a Bluesky account, but only when it differs from the
     * default discovery target — so reconnect can re-run OAuth against a custom
     * service URL instead of silently falling back to bsky.social.
     */
    private function customPdsUrl(ConnectedAccount $account): ?string
    {
        if ($account->platform !== Platform::Bluesky) {
            return null;
        }

        $pds = $account->secret?->session['pds'] ?? null;

        if (! is_string($pds) || $pds === '' || rtrim($pds, '/') === 'https://bsky.social') {
            return null;
        }

        return $pds;
    }

    public function reconnect(Request $request, ConnectedAccount $account): RedirectResponse
    {
        $request->user()->can('update', $account) ?: abort(403);

        // OAuth accounts reconnect by re-running the provider flow (which upserts
        // onto this row via the unique constraint); only app-password accounts
        // resubmit credentials here.
        if (! $account->platform->supportsAppPassword()) {
            if (! $account->platform->isConfigured()) {
                return redirect()->route('accounts.index')
                    ->with('error', "{$account->platform->label()} is not configured for reconnection.");
            }

            return redirect()->route('accounts.connect', ['platform' => $account->platform->value]);
        }

        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'app_password' => ['required', 'string', 'max:255'],
            'pds_url' => ['nullable', 'url', 'max:255'],
        ]);

        try {
            $data = $this->connector->connect(
                $validated['identifier'],
                $validated['app_password'],
                $validated['pds_url'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($data->remoteAccountId !== $account->remote_account_id) {
            return back()->withErrors([
                'identifier' => 'Those credentials are for a different Bluesky account.',
            ]);
        }

        $this->connections->store($data, $request->user());

        return redirect()->route('accounts.index')->with('success', 'Account reconnected.');
    }

    public function makeDefault(Request $request, ConnectedAccount $account): RedirectResponse
    {
        $request->user()->can('update', $account) ?: abort(403);

        $workspace = $request->user()->currentWorkspace()->firstOrFail();

        $workspace->forceFill([
            'default_connected_account_id' => $account->id,
        ])->save();

        return redirect()->route('accounts.index')->with('success', "{$account->handle} is now the default account.");
    }

    public function destroy(Request $request, ConnectedAccount $account): RedirectResponse
    {
        $request->user()->can('delete', $account) ?: abort(403);

        $workspace = $request->user()->currentWorkspace()->first();
        if ($workspace?->default_connected_account_id === $account->id) {
            $workspace->forceFill(['default_connected_account_id' => null])->save();
        }

        $account->secret()->delete();
        $account->delete();
        Inertia::clearHistory();

        return redirect()->route('accounts.index')->with('success', 'Account disconnected.');
    }
}
