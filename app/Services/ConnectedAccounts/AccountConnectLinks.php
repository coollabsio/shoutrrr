<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use App\Enums\Platform;
use App\Http\Controllers\ConnectedAccounts\LinkedInPageOAuthController;
use App\Models\ConnectedAccount;
use App\Support\AccountConnectReturn;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\URL;

/**
 * Browser URLs that start Classic's connect and reconnect flows. Neither MCP nor the REST API ever
 * completes provider OAuth or stores a webhook or app password.
 *
 * Links for an agent come from forWorkspace(): they are signed and carry the agent's workspace, so
 * SwitchWorkspaceFromConnectLink moves the human's browser into that workspace before the flow
 * starts. Without it the account would land in whatever workspace the browser happens to have open.
 */
class AccountConnectLinks
{
    public const SIGNED_LINK_MINUTES = 60;

    private ?string $workspaceId = null;

    public function __construct(private readonly InstanceSettings $settings) {}

    /**
     * Copy whose URLs are signed and switch the browser to the given workspace.
     */
    public function forWorkspace(string $workspaceId): self
    {
        $links = clone $this;
        $links->workspaceId = $workspaceId;

        return $links;
    }

    /**
     * @return list<array{platform: string, label: string, method: string, available: bool, reason: string|null, url: string|null, human_action: string}>
     */
    public function list(): array
    {
        $rows = [];

        foreach (Platform::cases() as $platform) {
            $rows[] = $this->describe($platform->value);
        }

        $rows[] = $this->describe('linkedin-pages');

        return $rows;
    }

    /**
     * Same as list(), but each OAuth URL brings the user back to the Agentic
     * home when the provider finishes. Webhook and app-password rows still open
     * the Accounts page, because the human pastes the secret there.
     *
     * @return list<array{platform: string, label: string, method: string, available: bool, reason: string|null, url: string|null, human_action: string}>
     */
    public function listReturningToAgentic(): array
    {
        return array_map(function (array $row): array {
            if ($row['method'] === 'oauth' && $row['url'] !== null) {
                $separator = str_contains($row['url'], '?') ? '&' : '?';
                $row['url'] .= $separator.http_build_query(['return' => AccountConnectReturn::QUERY_VALUE]);
            }

            return $row;
        }, $this->list());
    }

    /**
     * @param  array{identifier?: string|null, pds_url?: string|null}  $query
     * @return array{platform: string, label: string, method: string, available: bool, reason: string|null, url: string|null, human_action: string}
     */
    public function describe(string $platform, array $query = []): array
    {
        $key = $this->normalize($platform);

        if ($key === 'linkedin-pages') {
            return $this->linkedinPages();
        }

        $resolved = Platform::tryFrom($key);

        if ($resolved === null) {
            return $this->unavailable($key, $key, 'unknown', 'Unknown platform. List the platforms to see the supported values.');
        }

        if (! $resolved->isLaunched() || ! $this->settings->platformAvailable($resolved)) {
            return $this->unavailable($resolved->value, $resolved->label(), $this->methodFor($resolved), "{$resolved->label()} is disabled on this instance.");
        }

        if ($resolved->supportsWebhook()) {
            return $this->available(
                $resolved->value,
                $resolved->label(),
                'webhook',
                $this->url('accounts.index'),
                'Discord connects with a webhook, not OAuth. Open this page and paste the webhook there. Do not send the webhook URL to the agent.',
            );
        }

        if ($resolved === Platform::Bluesky) {
            return $this->bluesky($resolved, $query);
        }

        if (! $resolved->isConfigured()) {
            return $this->unavailable($resolved->value, $resolved->label(), 'oauth', "{$resolved->label()} is not configured on this instance.");
        }

        if ($resolved->usesMetaConnectionFlow()) {
            return $this->available(
                $resolved->value,
                $resolved->label(),
                'oauth',
                $this->url('accounts.meta.redirect'),
                'Open this URL and finish Facebook Login in the browser. Pick the Facebook Page or Instagram account there. The agent cannot complete OAuth.',
            );
        }

        return $this->available(
            $resolved->value,
            $resolved->label(),
            'oauth',
            $this->url('accounts.connect', ['platform' => $resolved->value]),
            'Open this URL and finish '.$resolved->label().' authorization in the browser. The agent cannot complete OAuth.',
        );
    }

    /**
     * @return array{platform: string, label: string, method: string, available: bool, reason: string|null, url: string|null, human_action: string}
     */
    public function reconnect(ConnectedAccount $account): array
    {
        if (! $this->settings->platformAvailable($account->platform)) {
            return $this->unavailable(
                $account->platform->value,
                $account->platform->label(),
                $this->methodFor($account->platform),
                "{$account->platform->label()} is disabled on this instance.",
            );
        }

        if ($account->platform === Platform::Bluesky && $account->auth_method === 'oauth') {
            return $this->bluesky($account->platform, ['identifier' => ltrim($account->handle, '@')]);
        }

        if ($account->auth_method === 'webhook') {
            return $this->available(
                $account->platform->value,
                $account->platform->label(),
                'webhook',
                $this->url('accounts.index'),
                'Open Accounts and paste a new Discord webhook for '.$account->handle.'. Do not send the webhook URL to the agent.',
            );
        }

        if ($account->platform === Platform::LinkedIn && $account->isLinkedInOrganization()) {
            return $this->linkedinPages();
        }

        if ($account->platform->supportsAppPassword() && $account->auth_method !== 'oauth') {
            return $this->available(
                $account->platform->value,
                $account->platform->label(),
                'app_password',
                $this->url('accounts.index'),
                'Open Accounts and resubmit the Bluesky app password for '.$account->handle.'. Do not send the password to the agent.',
            );
        }

        return $this->describe($account->platform->value);
    }

    /**
     * @param  array{identifier?: string|null, pds_url?: string|null}  $query
     * @return array{platform: string, label: string, method: string, available: bool, reason: string|null, url: string|null, human_action: string}
     */
    private function bluesky(Platform $platform, array $query): array
    {
        $parameters = array_filter([
            'identifier' => $query['identifier'] ?? null,
            'pds_url' => $query['pds_url'] ?? null,
        ], fn (?string $value): bool => is_string($value) && $value !== '');

        return $this->available(
            $platform->value,
            $platform->label(),
            'oauth',
            $this->url('accounts.bluesky.oauth', $parameters),
            'Open this URL to start Bluesky OAuth in the browser. An app password, if you use one instead, is entered on the Accounts page — do not send it to the agent.',
        );
    }

    /**
     * @return array{platform: string, label: string, method: string, available: bool, reason: string|null, url: string|null, human_action: string}
     */
    private function linkedinPages(): array
    {
        if (! $this->settings->linkedinCommunityManagementEnabled() || ! LinkedInPageOAuthController::pagesAppConfigured()) {
            return $this->unavailable(
                'linkedin-pages',
                'LinkedIn Pages',
                'oauth',
                'LinkedIn Pages is not configured on this instance.',
            );
        }

        return $this->available(
            'linkedin-pages',
            'LinkedIn Pages',
            'oauth',
            $this->url('accounts.linkedin-pages.redirect'),
            'Open this URL and finish LinkedIn Pages authorization in the browser. The agent cannot complete OAuth.',
        );
    }

    /**
     * The signature covers the path and query only, so the link still verifies
     * when a proxy changes the scheme or host the app sees.
     *
     * @param  array<string, string>  $parameters
     */
    private function url(string $route, array $parameters = []): string
    {
        if ($this->workspaceId === null) {
            return route($route, $parameters);
        }

        return url(URL::temporarySignedRoute(
            $route,
            now()->addMinutes(self::SIGNED_LINK_MINUTES),
            [...$parameters, 'workspace' => $this->workspaceId],
            absolute: false,
        ));
    }

    private function methodFor(Platform $platform): string
    {
        if ($platform->supportsWebhook()) {
            return 'webhook';
        }

        if ($platform->usesMetaConnectionFlow() || $platform->supportsOAuth()) {
            return 'oauth';
        }

        return 'app_password';
    }

    private function normalize(string $platform): string
    {
        return str_replace('_', '-', strtolower(trim($platform)));
    }

    /**
     * @return array{platform: string, label: string, method: string, available: bool, reason: string|null, url: string|null, human_action: string}
     */
    private function available(string $platform, string $label, string $method, string $url, string $humanAction): array
    {
        return [
            'platform' => $platform,
            'label' => $label,
            'method' => $method,
            'available' => true,
            'reason' => null,
            'url' => $url,
            'human_action' => $humanAction,
        ];
    }

    /**
     * @return array{platform: string, label: string, method: string, available: bool, reason: string|null, url: string|null, human_action: string}
     */
    private function unavailable(string $platform, string $label, string $method, string $reason): array
    {
        return [
            'platform' => $platform,
            'label' => $label,
            'method' => $method,
            'available' => false,
            'reason' => $reason,
            'url' => null,
            'human_action' => $reason,
        ];
    }
}
