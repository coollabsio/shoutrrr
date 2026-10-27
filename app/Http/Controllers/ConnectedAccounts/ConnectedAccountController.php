<?php

declare(strict_types=1);

namespace App\Http\Controllers\ConnectedAccounts;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectedAccount\UpdateAutoRepostRequest;
use App\Models\ConnectedAccount;
use App\Services\ConnectedAccounts\AccountConnectionService;
use App\Services\ConnectedAccounts\BlueskyConnector;
use App\Services\ConnectedAccounts\ConnectedAccountSettings;
use App\Services\ConnectedAccounts\DiscordConnector;
use App\Services\ConnectedAccounts\XAccountTierRefresher;
use App\Support\InstanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ConnectedAccountController extends Controller
{
    public function __construct(
        private readonly BlueskyConnector $connector,
        private readonly DiscordConnector $discord,
        private readonly AccountConnectionService $connections,
        private readonly XAccountTierRefresher $xTiers,
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
                'max_video_duration_seconds' => $account->maxVideoDurationSeconds(),
                'x_premium' => $account->hasXPremium(),
                'x_subscription_tier' => $account->xSubscriptionTier(),
                'x_subscription_label' => $account->xSubscriptionLabel(),
                'x_subscription_checked_at' => $account->xSubscriptionCheckedAt(),
                'is_linkedin_page' => $account->isLinkedInOrganization(),
                'is_default' => $account->id === $defaultAccountId,
                'disabled' => $account->isDisabled(),
                'pds_url' => $this->customPdsUrl($account),
                'auto_repost_enabled' => $account->autoRepostEnabled(),
            ])
            ->values()
            ->all();

        return Inertia::render('accounts/index', [
            'accounts' => $accounts,
            'capabilities' => Platform::capabilities(),
            'canManage' => $request->user()->can('create', ConnectedAccount::class),
            // LinkedIn Pages is a distinct connect entry backed by a separate
            // Community Management app; expose whether the operator has opted in
            // and configured it so the connect menu can show it (or "Not set up").
            'linkedinPagesEnabled' => app(InstanceSettings::class)->linkedinCommunityManagementEnabled(),
            'linkedinPagesConfigured' => LinkedInPageOAuthController::pagesAppConfigured(),
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

        if (! app(InstanceSettings::class)->platformAvailable($account->platform)) {
            return redirect()->route('accounts.index')
                ->with('error', "{$account->platform->label()} is disabled on this instance.");
        }

        // OAuth accounts reconnect by re-running the provider flow (which upserts
        // onto this row via the unique constraint); only app-password accounts
        // resubmit credentials here.
        if ($account->platform === Platform::Bluesky && $account->auth_method === 'oauth') {
            if (! $account->platform->isConfigured()) {
                return redirect()->route('accounts.index')
                    ->with('error', "{$account->platform->label()} is not configured for reconnection.");
            }

            return redirect()->route('accounts.bluesky.oauth', ['identifier' => ltrim($account->handle, '@')]);
        }

        // Webhook accounts (Discord) reconnect by resubmitting a webhook URL.
        // A deleted-and-recreated webhook has a new remote id, so adopt it onto
        // this row in place instead of upserting — which, keyed on the new id,
        // would spawn a duplicate card rather than heal the existing one.
        if ($account->auth_method === 'webhook') {
            $validated = $request->validate([
                'webhook_url' => ['required', 'string', 'max:2048'],
            ]);

            try {
                $data = $this->discord->connect($validated['webhook_url']);
            } catch (RuntimeException $exception) {
                return back()->with('error', $exception->getMessage());
            }

            $this->connections->reconnect($account, $data, $request->user());

            return redirect()->route('accounts.index')->with('success', 'Account reconnected.');
        }

        // LinkedIn Pages reconnect against the dedicated Community Management app,
        // not the personal `linkedin-openid` flow — its token was minted there.
        // The Pages redirect route 404s unless BOTH the toggle is on and the app
        // is configured, so mirror that here and flash a friendly reason instead
        // of bouncing the user into a bare 404.
        if ($account->platform === Platform::LinkedIn && $account->isLinkedInOrganization()) {
            if (
                ! app(InstanceSettings::class)->linkedinCommunityManagementEnabled()
                || ! LinkedInPageOAuthController::pagesAppConfigured()
            ) {
                return redirect()->route('accounts.index')
                    ->with('error', 'LinkedIn Pages is not configured for reconnection.');
            }

            return redirect()->route('accounts.linkedin-pages.redirect');
        }

        if (! $account->platform->supportsAppPassword()) {
            if (! $account->platform->isConfigured()) {
                return redirect()->route('accounts.index')
                    ->with('error', "{$account->platform->label()} is not configured for reconnection.");
            }

            // Facebook/Instagram reconnect by re-running the shared Meta
            // Login + Page-selection flow, not the generic per-platform route.
            if ($account->platform->usesMetaConnectionFlow()) {
                return redirect()->route('accounts.meta.redirect');
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

    public function makeDefault(Request $request, ConnectedAccount $account, ConnectedAccountSettings $settings): RedirectResponse
    {
        $request->user()->can('update', $account) ?: abort(403);

        $settings->makeDefault($account);

        return redirect()->route('accounts.index')->with('success', "{$account->handle} is now the default account.");
    }

    public function toggle(Request $request, ConnectedAccount $account, ConnectedAccountSettings $settings): RedirectResponse
    {
        $request->user()->can('update', $account) ?: abort(403);

        $settings->setEnabled($account, $account->isDisabled());

        $message = $account->isDisabled()
            ? "{$account->handle} is disabled."
            : "{$account->handle} is enabled.";

        return redirect()->route('accounts.index')->with('success', $message);
    }

    public function autoRepost(UpdateAutoRepostRequest $request, ConnectedAccount $account, ConnectedAccountSettings $settings): RedirectResponse
    {
        $validated = $request->validated();

        $settings->setAutoRepost(
            $account,
            (bool) $validated['enabled'],
            isset($validated['min_percentile']) ? (float) $validated['min_percentile'] : null,
        );

        return redirect()->route('accounts.index')->with('success', 'Auto-repost updated.');
    }

    public function refreshXAccountTier(Request $request, ConnectedAccount $account): RedirectResponse
    {
        $request->user()->can('update', $account) ?: abort(403);

        if ($account->platform !== Platform::X) {
            return back()->with('error', 'Only X accounts have a subscription tier.');
        }

        if (! $this->xTiers->refresh($account)) {
            return back()->with('error', "We couldn't refresh {$account->handle}'s X account tier. Your existing limit was kept.");
        }

        return back()->with(
            'success',
            "{$account->handle} is {$account->xSubscriptionLabel()} — {$account->maxTextLength()} characters per X post and up to {$account->maxVideoDurationSeconds()} seconds of video.",
        );
    }

    public function destroy(Request $request, ConnectedAccount $account, ConnectedAccountSettings $settings): RedirectResponse
    {
        $request->user()->can('delete', $account) ?: abort(403);

        $settings->disconnect($account);
        Inertia::clearHistory();

        return redirect()->route('accounts.index')->with('success', 'Account disconnected.');
    }
}
