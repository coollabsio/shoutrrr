<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\InstanceSettings;

enum Platform: string
{
    case X = 'x';
    case Bluesky = 'bluesky';
    case LinkedIn = 'linkedin';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Threads = 'threads';
    case Discord = 'discord';

    public function label(): string
    {
        return match ($this) {
            self::X => 'X',
            self::Bluesky => 'Bluesky',
            self::LinkedIn => 'LinkedIn',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::Threads => 'Threads',
            self::Discord => 'Discord',
        };
    }

    public function socialiteDriver(): ?string
    {
        return match ($this) {
            self::X => 'x',
            self::LinkedIn => 'linkedin-openid',
            self::Bluesky => null,
            self::Facebook, self::Instagram => 'facebook',
            self::Threads => 'threads',
            self::Discord => null,
        };
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            // `users.email` is required because Socialite's X driver always
            // requests the `confirmed_email` field from /2/users/me; without the
            // scope that call 403s ("Missing required OAuth2 scopes: users.email").
            // `media.write` is required to upload media to the v2 /2/media/upload
            // endpoint (the v1.1 endpoint was deprecated 2025-03-31).
            self::X => ['users.read', 'users.email', 'tweet.read', 'tweet.write', 'media.write', 'offline.access'],
            self::LinkedIn => ['openid', 'profile', 'email', 'w_member_social'],
            self::Bluesky => [],
            self::Facebook => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_read_user_content', 'pages_manage_engagement', 'read_insights', 'business_management'],
            self::Instagram => ['instagram_basic', 'instagram_content_publish', 'instagram_manage_comments', 'instagram_manage_insights', 'pages_show_list', 'business_management'],
            self::Threads => ['threads_basic', 'threads_content_publish', 'threads_manage_replies', 'threads_manage_insights'],
            self::Discord => [],
        };
    }

    public function configKey(): ?string
    {
        return match ($this) {
            self::X => 'services.x',
            self::LinkedIn => 'services.linkedin-openid',
            self::Bluesky => null,
            self::Facebook, self::Instagram => 'services.facebook',
            self::Threads => 'services.threads',
            self::Discord => null,
        };
    }

    public function supportsOAuth(): bool
    {
        return $this->socialiteDriver() !== null;
    }

    public function supportsAppPassword(): bool
    {
        return $this === self::Bluesky;
    }

    public function supportsWebhook(): bool
    {
        return $this === self::Discord;
    }

    /**
     * Whether this platform can read replies/mentions for the engagement inbox.
     * Discord webhooks are write-only — they can't receive replies — so Discord
     * has no engagement connector and must never be scheduled for reply fetching
     * (see the gate in InstanceSettings::engagementPollingEnabled, Task 6).
     */
    public function supportsEngagement(): bool
    {
        return $this !== self::Discord;
    }

    /**
     * Whether this platform's metrics connector returns real post-level metrics.
     * LinkedIn's post-metrics connector returns `unsupported`, so it has no
     * post-metrics polling to configure.
     */
    public function supportsPostMetrics(): bool
    {
        return $this !== self::LinkedIn;
    }

    /**
     * Whether this platform's metrics connector returns real account-level
     * metrics. LinkedIn and Discord return `unsupported` (LinkedIn has no
     * account-metrics API here; a Discord webhook cannot read server stats).
     */
    public function supportsAccountMetrics(): bool
    {
        return $this !== self::LinkedIn && $this !== self::Discord;
    }

    /**
     * Whether this platform participates in the given polling settings section.
     */
    public function supportsPollingSection(string $section): bool
    {
        return match ($section) {
            'engagement' => $this->supportsEngagement(),
            'post_metrics' => $this->supportsPostMetrics(),
            'account_metrics' => $this->supportsAccountMetrics(),
            default => false,
        };
    }

    /**
     * Launched platforms whose connectors back the given polling section, in
     * enum declaration order. Single source of truth for the polling settings
     * controller and its update request.
     *
     * @return list<self>
     */
    public static function pollingSectionPlatforms(string $section): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $platform): bool => $platform->isLaunched() && $platform->supportsPollingSection($section),
        ));
    }

    public function isConfigured(): bool
    {
        if ($this->supportsAppPassword() || $this->supportsWebhook()) {
            return true;
        }

        $key = $this->configKey();

        // filled() so a blank value in .env isn't treated as configured.
        return $key !== null
            && filled(config($key.'.client_id'))
            && filled(config($key.'.client_secret'));
    }

    /**
     * Whether this platform's connect + publishing flow is fully implemented
     * and safe to expose. New platforms are registered in this enum (for limits,
     * branding, and phased rollout) before their connectors exist; until then
     * connecting must stay disabled even when credentials are configured. Flip a
     * platform to `true` when its publish/engagement/metrics connectors land.
     *
     * All seven platforms (X, Bluesky, LinkedIn, Facebook, Instagram, Threads,
     * Discord) are launched.
     */
    public function isLaunched(): bool
    {
        return true;
    }

    /**
     * The subset of the Facebook-Login-driven Meta platforms (Facebook,
     * Instagram) that are launched — used to gate the shared
     * `MetaConnectionController` flow and to scope the Facebook Login
     * request to only the permissions a launched platform actually needs.
     *
     * @return list<self>
     */
    public static function launchedMetaGraphPlatforms(): array
    {
        return array_values(array_filter(
            [self::Facebook, self::Instagram],
            fn (self $platform): bool => $platform->isLaunched(),
        ));
    }

    /**
     * The launched Meta-Graph platforms (Facebook, Instagram) that are ALSO
     * enabled instance-wide — used to gate the shared Meta connect flow and the
     * per-asset platform list so an owner can freeze Facebook or Instagram
     * independently.
     *
     * @return list<self>
     */
    public static function availableMetaGraphPlatforms(): array
    {
        $enabled = app(InstanceSettings::class)->platformsEnabled();

        return array_values(array_filter(
            [self::Facebook, self::Instagram],
            fn (self $platform): bool => $platform->isLaunched() && ($enabled[$platform->value] ?? true),
        ));
    }

    /**
     * Facebook and Instagram share a single Facebook Login flow with a
     * Page/asset-selection step, driven by `MetaConnectionController`. The
     * generic per-platform `OAuthConnectionController` (a single-step
     * socialite-user-to-account mapping) must never handle them — even once
     * launched — because it has no notion of picking a Page.
     */
    public function usesMetaConnectionFlow(): bool
    {
        return $this === self::Facebook || $this === self::Instagram;
    }

    /**
     * @return list<array{platform: string, label: string, supportsOAuth: bool, supportsAppPassword: bool, supportsWebhook: bool, configured: bool, launched: bool, enabled: bool}>
     */
    public static function capabilities(): array
    {
        $enabled = app(InstanceSettings::class)->platformsEnabled();

        return array_map(fn (self $platform): array => [
            'platform' => $platform->value,
            'label' => $platform->label(),
            'supportsOAuth' => $platform->supportsOAuth(),
            'supportsAppPassword' => $platform->supportsAppPassword(),
            'supportsWebhook' => $platform->supportsWebhook(),
            'configured' => $platform->isConfigured(),
            'launched' => $platform->isLaunched(),
            'enabled' => $enabled[$platform->value] ?? true,
        ], self::cases());
    }

    /**
     * The primary length budget, in each platform's native counting unit
     * (X: UTF-16 code units, Bluesky: graphemes; LinkedIn, Facebook, Instagram,
     * and Threads: characters via mb_strlen).
     */
    public function maxLength(): int
    {
        return match ($this) {
            self::X => 280,
            self::Bluesky => 300,
            self::LinkedIn => 3000,
            self::Facebook => 63_206,
            self::Instagram => 2_200,
            self::Threads => 500,
            self::Discord => 2000,
        };
    }

    /**
     * Secondary byte budget (Bluesky only); null when the platform has none.
     */
    public function maxBytes(): ?int
    {
        return match ($this) {
            self::Bluesky => 3000,
            default => null,
        };
    }

    /**
     * Maximum number of posts a single draft may thread into; null = unlimited.
     */
    public function threadMax(): ?int
    {
        return match ($this) {
            self::LinkedIn, self::Facebook, self::Instagram => 1,
            default => null,
        };
    }

    public function maxMedia(): int
    {
        return match ($this) {
            self::X, self::Bluesky => 4,
            self::LinkedIn => 9,
            self::Facebook, self::Instagram, self::Threads => 10,
            self::Discord => 10,
        };
    }

    public function maxMediaBytes(): int
    {
        return match ($this) {
            self::Bluesky => 2_000_000,
            self::X => 5_242_880,
            self::LinkedIn => 8_388_608,
            self::Facebook => 4_194_304,
            self::Instagram, self::Threads => 8_388_608,
            self::Discord => 10_485_760, // 10 MiB (Discord's default webhook attachment cap)
        };
    }

    /**
     * @return list<string>
     */
    public function allowedMime(): array
    {
        return match ($this) {
            self::X, self::Bluesky => ['image/jpeg', 'image/png', 'image/webp'],
            self::LinkedIn => ['image/jpeg', 'image/png', 'image/gif'],
            self::Facebook => ['image/jpeg', 'image/png', 'image/gif'],
            self::Instagram => ['image/jpeg'],
            self::Threads => ['image/jpeg', 'image/png'],
            self::Discord => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        };
    }

    /**
     * @return array{width: int, height: int}
     */
    public function maxImageDimensions(): array
    {
        return match ($this) {
            self::Bluesky => ['width' => 2000, 'height' => 2000],
            self::X => ['width' => 8192, 'height' => 8192],
            self::LinkedIn => ['width' => 7680, 'height' => 4320],
            self::Facebook => ['width' => 8192, 'height' => 8192],
            self::Instagram, self::Threads => ['width' => 1440, 'height' => 1800],
            self::Discord => ['width' => 8192, 'height' => 8192],
        };
    }

    /**
     * @return list<string>
     */
    public function allowedVideoMime(): array
    {
        // mp4 (H.264/AAC) is the common denominator all three accept directly.
        return ['video/mp4'];
    }

    public function maxVideoBytes(): int
    {
        return match ($this) {
            self::X => 536_870_912,        // 512 MB
            self::LinkedIn => 524_288_000, // 500 MB (organic feed)
            self::Bluesky => 100_000_000,
            self::Facebook, self::Instagram, self::Threads => 1_073_741_824,
            self::Discord => 10_485_760, // 10 MiB (Discord's default webhook attachment cap)
        };
    }

    public function maxVideoDurationSeconds(): int
    {
        return match ($this) {
            self::X => 140,
            self::LinkedIn => 1800,
            self::Bluesky => 180,
            self::Facebook => 1200,
            self::Instagram => 900,
            self::Threads => 300,
            self::Discord => 600,
        };
    }

    /**
     * Largest video byte cap across all platforms — the server-side upload ceiling.
     */
    public static function maxVideoBytesCeiling(): int
    {
        return max(array_map(fn (self $p): int => $p->maxVideoBytes(), self::cases()));
    }

    /**
     * Measure a string in this platform's native counting unit.
     */
    public function measure(string $text): int
    {
        return match ($this) {
            // UTF-16 code units: 2 bytes each in UTF-16LE.
            self::X => intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2),
            self::Bluesky => grapheme_strlen($text) ?: 0,
            self::LinkedIn, self::Facebook, self::Instagram, self::Threads, self::Discord => mb_strlen($text),
        };
    }

    /**
     * @return array{platform: string, maxLength: int, maxBytes: int|null, maxMedia: int, maxMediaBytes: int, allowedMime: list<string>, threadMax: int|null, maxImageDimensions: array{width: int, height: int}, allowedVideoMime: list<string>, maxVideoBytes: int, maxVideoDurationSeconds: int}
     */
    public function limits(): array
    {
        return [
            'platform' => $this->value,
            'maxLength' => $this->maxLength(),
            'maxBytes' => $this->maxBytes(),
            'maxMedia' => $this->maxMedia(),
            'maxMediaBytes' => $this->maxMediaBytes(),
            'allowedMime' => $this->allowedMime(),
            'threadMax' => $this->threadMax(),
            'maxImageDimensions' => $this->maxImageDimensions(),
            'allowedVideoMime' => $this->allowedVideoMime(),
            'maxVideoBytes' => $this->maxVideoBytes(),
            'maxVideoDurationSeconds' => $this->maxVideoDurationSeconds(),
        ];
    }

    /**
     * @return list<array{platform: string, maxLength: int, maxBytes: int|null, maxMedia: int, maxMediaBytes: int, allowedMime: list<string>, threadMax: int|null, maxImageDimensions: array{width: int, height: int}, allowedVideoMime: list<string>, maxVideoBytes: int, maxVideoDurationSeconds: int}>
     */
    public static function allLimits(): array
    {
        return array_map(fn (self $platform): array => $platform->limits(), self::cases());
    }
}
