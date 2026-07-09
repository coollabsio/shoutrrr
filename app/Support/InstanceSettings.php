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
        return $this->platformEnabled('engagement_polling_enabled', $platform);
    }

    public function postMetricsPollingEnabled(?Platform $platform = null): bool
    {
        return $this->platformEnabled('post_metrics_polling_enabled', $platform);
    }

    public function accountMetricsPollingEnabled(?Platform $platform = null): bool
    {
        return $this->platformEnabled('account_metrics_polling_enabled', $platform);
    }

    public function quoteTweetsEnabled(): bool
    {
        return $this->boolean('quote_tweets_enabled');
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
     * @return array{
     *     engagement: array{enabled: array{x: bool, bluesky: bool, linkedin: bool}, x: int, bluesky: int, linkedin: int},
     *     post_metrics: array{enabled: array{x: bool, bluesky: bool, linkedin: bool}, x: int, bluesky: int, linkedin: int},
     *     account_metrics: array{enabled: array{x: bool, bluesky: bool, linkedin: bool}, x: int, bluesky: int, linkedin: int}
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
     * @param  array{registrations_enabled?: bool, workspace_creation_enabled?: bool, usage_tracking_enabled?: bool, quote_tweets_enabled?: bool, engagement_polling_enabled?: bool|array{x: bool, bluesky: bool, linkedin: bool}, post_metrics_polling_enabled?: bool|array{x: bool, bluesky: bool, linkedin: bool}, account_metrics_polling_enabled?: bool|array{x: bool, bluesky: bool, linkedin: bool}, engagement_poll_interval_minutes?: array{x: int, bluesky: int, linkedin: int}, post_metrics_poll_interval_minutes?: array{x: int, bluesky: int, linkedin: int}, account_metrics_poll_interval_minutes?: array{x: int, bluesky: int, linkedin: int}}  $values
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
     * @return array{x: bool, bluesky: bool, linkedin: bool}
     */
    private function platformEnabledValues(string $key): array
    {
        $stored = $this->settings()[$key] ?? true;

        if (is_bool($stored)) {
            return [
                Platform::X->value => $stored,
                Platform::Bluesky->value => $stored,
                Platform::LinkedIn->value => $stored,
            ];
        }

        $stored = (array) $stored;

        return [
            Platform::X->value => (bool) ($stored[Platform::X->value] ?? true),
            Platform::Bluesky->value => (bool) ($stored[Platform::Bluesky->value] ?? true),
            Platform::LinkedIn->value => (bool) ($stored[Platform::LinkedIn->value] ?? true),
        ];
    }

    /**
     * @return array{x: int, bluesky: int, linkedin: int}
     */
    private function platformMinutes(string $key, string $defaultKey): array
    {
        $stored = (array) ($this->settings()[$key] ?? []);
        $defaults = (array) config("instance.defaults.polling.{$defaultKey}", []);

        return [
            Platform::X->value => max(1, (int) ($stored[Platform::X->value] ?? $defaults[Platform::X->value] ?? 360)),
            Platform::Bluesky->value => max(1, (int) ($stored[Platform::Bluesky->value] ?? $defaults[Platform::Bluesky->value] ?? 15)),
            Platform::LinkedIn->value => max(1, (int) ($stored[Platform::LinkedIn->value] ?? $defaults[Platform::LinkedIn->value] ?? 15)),
        ];
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
