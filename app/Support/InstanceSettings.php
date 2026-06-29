<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\InstanceRole;
use App\Models\InstanceSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class InstanceSettings
{
    private const string CacheKey = 'instance_settings';

    public function registrationsEnabled(): bool
    {
        return $this->boolean('registrations_enabled');
    }

    public function workspaceCreationEnabled(): bool
    {
        return $this->boolean('workspace_creation_enabled');
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
     * @return array{registrations_enabled: bool, workspace_creation_enabled: bool}
     */
    public function all(): array
    {
        return [
            'registrations_enabled' => $this->registrationsEnabled(),
            'workspace_creation_enabled' => $this->workspaceCreationEnabled(),
        ];
    }

    /**
     * @param  array{registrations_enabled?: bool, workspace_creation_enabled?: bool}  $values
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

    public function aiEnabled(): bool
    {
        return $this->boolean('ai_enabled');
    }

    public function aiProvider(): string
    {
        $value = $this->value('ai_provider');

        return is_string($value) && $value !== '' ? $value : (string) config('ai.provider');
    }

    public function aiModel(): string
    {
        $value = $this->value('ai_model');

        return is_string($value) && $value !== '' ? $value : (string) config('ai.model');
    }

    public function aiApiKey(): ?string
    {
        $stored = $this->value('ai_api_key');

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    public function aiConfigured(): bool
    {
        return $this->aiEnabled() && $this->aiApiKey() !== null;
    }

    /**
     * @return array{ai_enabled: bool, ai_provider: string, ai_model: string, ai_api_key_set: bool}
     */
    public function aiSettings(): array
    {
        return [
            'ai_enabled' => $this->aiEnabled(),
            'ai_provider' => $this->aiProvider(),
            'ai_model' => $this->aiModel(),
            'ai_api_key_set' => $this->aiApiKey() !== null,
        ];
    }

    /**
     * @param  array{ai_enabled: bool, ai_provider: string, ai_model: string}  $values
     */
    public function updateAi(array $values, ?string $apiKey): void
    {
        foreach ($values as $key => $value) {
            InstanceSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        if ($apiKey !== null) {
            InstanceSetting::query()->updateOrCreate(
                ['key' => 'ai_api_key'],
                ['value' => $apiKey === '' ? '' : Crypt::encryptString($apiKey)],
            );
        }

        Cache::forget(self::CacheKey);
    }

    private function boolean(string $key): bool
    {
        return (bool) $this->value($key);
    }

    private function value(string $key): mixed
    {
        /** @var array<string, mixed> $settings */
        $settings = Cache::rememberForever(self::CacheKey, fn (): array => InstanceSetting::query()
            ->get()
            ->mapWithKeys(fn (InstanceSetting $setting): array => [$setting->key => $setting->value])
            ->all());

        return $settings[$key] ?? config("instance.defaults.{$key}");
    }
}
