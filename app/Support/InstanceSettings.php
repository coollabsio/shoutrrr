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
     * @return array{registrations_enabled: bool, workspace_creation_enabled: bool, usage_tracking_enabled: bool}
     */
    public function all(): array
    {
        return [
            'registrations_enabled' => $this->registrationsEnabled(),
            'workspace_creation_enabled' => $this->workspaceCreationEnabled(),
            'usage_tracking_enabled' => $this->usageTrackingEnabled(),
        ];
    }

    /**
     * @return array{
     *     engagement: array{x: int, bluesky: int, linkedin: int},
     *     post_metrics: array{x: int, bluesky: int, linkedin: int},
     *     account_metrics: array{x: int, bluesky: int, linkedin: int}
     * }
     */
    public function polling(): array
    {
        return [
            'engagement' => $this->platformMinutes('engagement_poll_interval_minutes', 'engagement'),
            'post_metrics' => $this->platformMinutes('post_metrics_poll_interval_minutes', 'post_metrics'),
            'account_metrics' => $this->platformMinutes('account_metrics_poll_interval_minutes', 'account_metrics'),
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
     * @param  array{registrations_enabled?: bool, workspace_creation_enabled?: bool, usage_tracking_enabled?: bool}  $values
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

    private function boolean(string $key): bool
    {
        return (bool) ($this->settings()[$key] ?? config("instance.defaults.{$key}"));
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
