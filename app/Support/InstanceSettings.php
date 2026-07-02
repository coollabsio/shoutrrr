<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\InstanceRole;
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
        /** @var array<string, mixed> $settings */
        $settings = Cache::rememberForever(self::CacheKey, fn (): array => InstanceSetting::query()
            ->get()
            ->mapWithKeys(fn (InstanceSetting $setting): array => [$setting->key => $setting->value])
            ->all());

        return (bool) ($settings[$key] ?? config("instance.defaults.{$key}"));
    }
}
