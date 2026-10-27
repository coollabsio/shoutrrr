<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use App\Enums\Platform;
use App\Http\Controllers\ConnectedAccounts\LinkedInPageOAuthController;
use App\Models\ConnectedAccount;
use App\Support\InstanceSettings;

/**
 * Browser URLs that start Classic's connect and reconnect flows. MCP never
 * completes provider OAuth or stores a webhook or app password.
 */
class AccountConnectLinks
{
    public function __construct(private readonly InstanceSettings $settings) {}

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
            return $this->unavailable($key, $key, 'unknown', 'Unknown platform. Use list_platforms for the supported values.');
        }

        if (! $resolved->isLaunched() || ! $this->settings->platformAvailable($resolved)) {
            return $this->unavailable($resolved->value, $resolved->label(), $this->methodFor($resolved), "{$resolved->label()} is disabled on this instance.");
        }

        if ($resolved->supportsWebhook()) {
            return $this->available(
                $resolved->value,
                $resolved->label(),
                'webhook',
                route('accounts.index'),
                'Discord connects with a webhook, not OAuth. Open this page and paste the webhook there. Do not send the webhook URL to MCP.',
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
                route('accounts.meta.redirect'),
                'Open this URL and finish Facebook Login in the browser. Pick the Facebook Page or Instagram account there. MCP does not complete OAuth.',
            );
        }

        return $this->available(
            $resolved->value,
            $resolved->label(),
            'oauth',
            route('accounts.connect', ['platform' => $resolved->value]),
            'Open this URL and finish '.$resolved->label().' authorization in the browser. MCP does not complete OAuth.',
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
                route('accounts.index'),
                'Open Accounts and paste a new Discord webhook for '.$account->handle.'. Do not send the webhook URL to MCP.',
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
                route('accounts.index'),
                'Open Accounts and resubmit the Bluesky app password for '.$account->handle.'. Do not send the password to MCP.',
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
            route('accounts.bluesky.oauth', $parameters),
            'Open this URL to start Bluesky OAuth in the browser. An app password, if you use one instead, is entered on the Accounts page — do not send it to MCP.',
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
            route('accounts.linkedin-pages.redirect'),
            'Open this URL and finish LinkedIn Pages authorization in the browser. MCP does not complete OAuth.',
        );
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
