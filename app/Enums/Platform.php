<?php

declare(strict_types=1);

namespace App\Enums;

enum Platform: string
{
    case X = 'x';
    case Bluesky = 'bluesky';
    case LinkedIn = 'linkedin';

    public function label(): string
    {
        return match ($this) {
            self::X => 'X',
            self::Bluesky => 'Bluesky',
            self::LinkedIn => 'LinkedIn',
        };
    }

    public function socialiteDriver(): ?string
    {
        return match ($this) {
            self::X => 'x',
            self::LinkedIn => 'linkedin-openid',
            self::Bluesky => null,
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
        };
    }

    public function configKey(): ?string
    {
        return match ($this) {
            self::X => 'services.x',
            self::LinkedIn => 'services.linkedin-openid',
            self::Bluesky => null,
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

    public function isConfigured(): bool
    {
        if ($this->supportsAppPassword()) {
            return true;
        }

        $key = $this->configKey();

        // filled() so a blank value in .env isn't treated as configured.
        return $key !== null
            && filled(config($key.'.client_id'))
            && filled(config($key.'.client_secret'));
    }

    /**
     * @return list<array{platform: string, label: string, supportsOAuth: bool, supportsAppPassword: bool, configured: bool}>
     */
    public static function capabilities(): array
    {
        return array_map(fn (self $platform): array => [
            'platform' => $platform->value,
            'label' => $platform->label(),
            'supportsOAuth' => $platform->supportsOAuth(),
            'supportsAppPassword' => $platform->supportsAppPassword(),
            'configured' => $platform->isConfigured(),
        ], self::cases());
    }

    /**
     * The primary length budget, in each platform's native counting unit
     * (X: UTF-16 code units, Bluesky: graphemes, LinkedIn: characters).
     */
    public function maxLength(): int
    {
        return match ($this) {
            self::X => 280,
            self::Bluesky => 300,
            self::LinkedIn => 3000,
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
            self::LinkedIn => 1,
            default => null,
        };
    }

    public function maxMedia(): int
    {
        return match ($this) {
            self::X, self::Bluesky => 4,
            self::LinkedIn => 9,
        };
    }

    public function maxMediaBytes(): int
    {
        return match ($this) {
            self::Bluesky => 1_048_576,
            self::X => 5_242_880,
            self::LinkedIn => 8_388_608,
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
            self::Bluesky => 104_857_600,  // 100 MB
        };
    }

    public function maxVideoDurationSeconds(): int
    {
        return match ($this) {
            self::X => 140,
            self::LinkedIn => 1800,
            self::Bluesky => 180,
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
            self::LinkedIn => mb_strlen($text),
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
