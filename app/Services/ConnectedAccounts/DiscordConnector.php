<?php

declare(strict_types=1);

namespace App\Services\ConnectedAccounts;

use App\Dto\ConnectedAccount\ConnectedAccountData;
use App\Enums\Platform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

class DiscordConnector
{
    /** @var list<string> */
    private const array ALLOWED_HOSTS = ['discord.com', 'canary.discord.com', 'ptb.discord.com', 'discordapp.com'];

    public function __construct(private readonly HttpFactory $http) {}

    public function connect(string $webhookUrl): ConnectedAccountData
    {
        $webhookUrl = trim($webhookUrl);
        $this->assertValidWebhookUrl($webhookUrl);

        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->acceptJson()
                ->get($webhookUrl);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach Discord. Please try again.');
        }

        if ($response->failed()) {
            throw new RuntimeException('Discord rejected that webhook URL. Check that it is correct and still exists.');
        }

        /** @var array<string, mixed> $webhook */
        $webhook = (array) $response->json();
        $id = (string) ($webhook['id'] ?? '');

        if ($id === '') {
            throw new RuntimeException('Discord did not return a webhook identity.');
        }

        $name = (string) ($webhook['name'] ?? 'Discord webhook');
        $avatarHash = $webhook['avatar'] ?? null;
        $avatarUrl = is_string($avatarHash) && $avatarHash !== ''
            ? "https://cdn.discordapp.com/avatars/{$id}/{$avatarHash}.png"
            : null;

        return new ConnectedAccountData(
            platform: Platform::Discord,
            remoteAccountId: $id,
            handle: $name,
            displayName: $name,
            avatarUrl: $avatarUrl,
            authMethod: 'webhook',
            accessToken: $webhookUrl,
            session: [
                'channel_id' => isset($webhook['channel_id']) ? (string) $webhook['channel_id'] : null,
                'guild_id' => isset($webhook['guild_id']) ? (string) $webhook['guild_id'] : null,
            ],
        );
    }

    /**
     * Only a real Discord webhook URL is accepted: https, a known Discord host,
     * and the `/api[/vN]/webhooks/{id}/{token}` path shape. This closes SSRF —
     * the host set is fixed, unlike an arbitrary user-supplied endpoint.
     *
     * @throws RuntimeException
     */
    public function assertValidWebhookUrl(string $url): void
    {
        // parse_url() silently substitutes embedded control characters (tab/CR/LF)
        // with `_` before we ever see the path, which would otherwise let a
        // malicious control character sneak through validation while the raw,
        // unsanitized $url is still the one used for the outbound request.
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            throw new RuntimeException('That does not look like a Discord webhook URL.');
        }

        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('The Discord webhook URL must use https.');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! in_array(strtolower($host), self::ALLOWED_HOSTS, true)) {
            throw new RuntimeException('That does not look like a Discord webhook URL.');
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('#^/api(?:/v\d+)?/webhooks/\d+/[\w-]+$#D', $path) !== 1) {
            throw new RuntimeException('That does not look like a Discord webhook URL.');
        }
    }
}
