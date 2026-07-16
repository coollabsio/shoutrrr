<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\InstanceRole;
use App\Enums\Platform;
use App\Models\InstanceSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class InstanceSettings
{
    public const string CacheKey = 'instance_settings';

    public function registrationsEnabled(): bool
    {
        return $this->boolean('registrations_enabled');
    }

    public function workspaceCreationEnabled(): bool
    {
        return $this->boolean('workspace_creation_enabled');
    }

    public function usageTrackingEnabled(): bool
    {
        return $this->boolean('usage_tracking_enabled');
    }

    public function engagementPollingEnabled(?Platform $platform = null): bool
    {
        // Discord (and any future write-only platform) has no engagement connector;
        // never report it as pollable so the reply-fetch dispatcher skips it.
        if ($platform !== null && ! $platform->supportsEngagement()) {
            return false;
        }

        return $this->platformAvailable($platform)
            && $this->platformEnabled('engagement_polling_enabled', $platform);
    }

    public function postMetricsPollingEnabled(?Platform $platform = null): bool
    {
        return $this->platformAvailable($platform)
            && $this->platformEnabled('post_metrics_polling_enabled', $platform);
    }

    public function accountMetricsPollingEnabled(?Platform $platform = null): bool
    {
        return $this->platformAvailable($platform)
            && $this->platformEnabled('account_metrics_polling_enabled', $platform);
    }

    public function quoteTweetsEnabled(): bool
    {
        return $this->boolean('quote_tweets_enabled');
    }

    /** Instance-wide metrics master switch. Defaults to `metrics.enabled` (env) until overridden here. */
    public function metricsEnabled(): bool
    {
        return $this->boolean('metrics_enabled', (bool) config('metrics.enabled'));
    }

    /** Instance-wide engagement master switch. Defaults to `engagement.enabled` (env) until overridden here. */
    public function engagementEnabled(): bool
    {
        return $this->boolean('engagement_enabled', (bool) config('engagement.enabled'));
    }

    public function platformAvailable(?Platform $platform = null): bool
    {
        return $this->platformEnabled('platforms_enabled', $platform);
    }

    /**
     * @return array<string, bool>
     */
    public function platformsEnabled(): array
    {
        return $this->platformEnabledValues('platforms_enabled');
    }

    public function registrationsAllowed(?string $invitationToken = null): bool
    {
        if (! $this->ownerExists()) {
            return true;
        }

        if ($invitationToken !== null && $invitationToken !== '') {
            return true;
        }

        return $this->registrationsEnabled();
    }

    public function ownerExists(): bool
    {
        return User::query()->where('instance_role', InstanceRole::Owner->value)->exists();
    }

    public function claimOwnerIfMissing(User $user): void
    {
        if ($this->ownerExists()) {
            return;
        }

        $user->forceFill(['instance_role' => InstanceRole::Owner])->save();
    }

    /**
     * @return array{registrations_enabled: bool, workspace_creation_enabled: bool, usage_tracking_enabled: bool, quote_tweets_enabled: bool}
     */
    public function all(): array
    {
        return [
            'registrations_enabled' => $this->registrationsEnabled(),
            'workspace_creation_enabled' => $this->workspaceCreationEnabled(),
            'usage_tracking_enabled' => $this->usageTrackingEnabled(),
            'quote_tweets_enabled' => $this->quoteTweetsEnabled(),
        ];
    }

    /**
     * Each section is a map of `enabled` (a per-platform bool map) plus one
     * poll-interval-in-minutes entry keyed by each platform value.
     *
     * @return array{
     *     engagement: array<string, array<string, bool>|int>,
     *     post_metrics: array<string, array<string, bool>|int>,
     *     account_metrics: array<string, array<string, bool>|int>,
     *     metrics_enabled: bool,
     *     engagement_enabled: bool
     * }
     */
    public function polling(): array
    {
        return [
            'engagement' => [
                'enabled' => $this->platformEnabledValues('engagement_polling_enabled'),
                ...$this->platformMinutes('engagement_poll_interval_minutes', 'engagement'),
            ],
            'post_metrics' => [
                'enabled' => $this->platformEnabledValues('post_metrics_polling_enabled'),
                ...$this->platformMinutes('post_metrics_poll_interval_minutes', 'post_metrics'),
            ],
            'account_metrics' => [
                'enabled' => $this->platformEnabledValues('account_metrics_polling_enabled'),
                ...$this->platformMinutes('account_metrics_poll_interval_minutes', 'account_metrics'),
            ],
            // Instance-wide master switches: when off, the sections above are moot
            // (nothing polls regardless of their per-platform settings).
            'metrics_enabled' => $this->metricsEnabled(),
            'engagement_enabled' => $this->engagementEnabled(),
        ];
    }

    public function engagementPollIntervalMinutes(Platform $platform): int
    {
        return $this->platformMinutes('engagement_poll_interval_minutes', 'engagement')[$platform->value];
    }

    public function postMetricsPollIntervalMinutes(Platform $platform): int
    {
        return $this->platformMinutes('post_metrics_poll_interval_minutes', 'post_metrics')[$platform->value];
    }

    public function accountMetricsPollIntervalMinutes(Platform $platform): int
    {
        return $this->platformMinutes('account_metrics_poll_interval_minutes', 'account_metrics')[$platform->value];
    }

    /**
     * @param  array{registrations_enabled?: bool, workspace_creation_enabled?: bool, usage_tracking_enabled?: bool, quote_tweets_enabled?: bool, engagement_polling_enabled?: bool|array<string, bool>, post_metrics_polling_enabled?: bool|array<string, bool>, account_metrics_polling_enabled?: bool|array<string, bool>, engagement_poll_interval_minutes?: array<string, int>, post_metrics_poll_interval_minutes?: array<string, int>, account_metrics_poll_interval_minutes?: array<string, int>}  $values
     */
    public function update(array $values): void
    {
        foreach ($values as $key => $value) {
            InstanceSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value],
            );
        }

        Cache::forget(self::CacheKey);
    }

    private function boolean(string $key, ?bool $default = null): bool
    {
        return (bool) ($this->settings()[$key] ?? $default ?? config("instance.defaults.{$key}"));
    }

    private function platformEnabled(string $key, ?Platform $platform): bool
    {
        $values = $this->platformEnabledValues($key);

        if ($platform !== null) {
            return $values[$platform->value];
        }

        return in_array(true, $values, true);
    }

    /**
     * @return array<string, bool>
     */
    private function platformEnabledValues(string $key): array
    {
        $stored = $this->settings()[$key] ?? true;

        if (is_bool($stored)) {
            return array_reduce(
                Platform::cases(),
                function (array $values, Platform $platform) use ($stored): array {
                    $values[$platform->value] = $stored;

                    return $values;
                },
                [],
            );
        }

        $stored = (array) $stored;

        return array_reduce(
            Platform::cases(),
            function (array $values, Platform $platform) use ($stored): array {
                $values[$platform->value] = (bool) ($stored[$platform->value] ?? true);

                return $values;
            },
            [],
        );
    }

    /**
     * @return array<string, int>
     */
    private function platformMinutes(string $key, string $defaultKey): array
    {
        $stored = (array) ($this->settings()[$key] ?? []);
        $defaults = (array) config("instance.defaults.polling.{$defaultKey}", []);

        return array_reduce(
            Platform::cases(),
            function (array $values, Platform $platform) use ($stored, $defaults): array {
                // X polls least often (strict rate limits / API cost); the rest default to 15 min.
                $fallback = $platform === Platform::X ? 360 : 15;
                $values[$platform->value] = max(1, (int) ($stored[$platform->value] ?? $defaults[$platform->value] ?? $fallback));

                return $values;
            },
            [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        /** @var array<string, mixed> $settings */
        $settings = Cache::rememberForever(self::CacheKey, fn (): array => InstanceSetting::query()
            ->get()
            ->mapWithKeys(fn (InstanceSetting $setting): array => [$setting->key => $setting->value])
            ->all());

        return $settings;
    }
}
