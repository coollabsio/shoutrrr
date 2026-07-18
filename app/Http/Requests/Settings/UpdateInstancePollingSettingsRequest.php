<?php

namespace App\Http\Requests\Settings;

use App\Enums\Platform;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInstancePollingSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user?->isInstanceOwner() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'metrics_enabled' => ['required', 'boolean'],
            'engagement_enabled' => ['required', 'boolean'],
        ];

        foreach (['engagement', 'post_metrics', 'account_metrics'] as $section) {
            $rules[$section] = ['required', 'array'];
            $rules["{$section}.enabled"] = ['required', 'array'];

            foreach (Platform::pollingSectionPlatforms($section) as $platform) {
                $rules["{$section}.enabled.{$platform->value}"] = ['required', 'boolean'];
                $rules["{$section}.{$platform->value}"] = ['required', 'integer', 'min:5', 'max:10080'];
            }
        }

        return $rules;
    }

    /**
     * @return array{
     *     engagement_polling_enabled: array<string, bool>,
     *     post_metrics_polling_enabled: array<string, bool>,
     *     account_metrics_polling_enabled: array<string, bool>,
     *     engagement_poll_interval_minutes: array<string, int>,
     *     post_metrics_poll_interval_minutes: array<string, int>,
     *     account_metrics_poll_interval_minutes: array<string, int>,
     *     metrics_enabled: bool,
     *     engagement_enabled: bool,
     * }
     */
    public function instancePollingSettings(): array
    {
        /** @var array{engagement: array<string, mixed>, post_metrics: array<string, mixed>, account_metrics: array<string, mixed>, metrics_enabled: mixed, engagement_enabled: mixed} $validated */
        $validated = $this->validated();

        return [
            'engagement_polling_enabled' => $this->enabledMap($validated['engagement']),
            'post_metrics_polling_enabled' => $this->enabledMap($validated['post_metrics']),
            'account_metrics_polling_enabled' => $this->enabledMap($validated['account_metrics']),
            'engagement_poll_interval_minutes' => $this->minutes($validated['engagement']),
            'post_metrics_poll_interval_minutes' => $this->minutes($validated['post_metrics']),
            'account_metrics_poll_interval_minutes' => $this->minutes($validated['account_metrics']),
            'metrics_enabled' => (bool) $validated['metrics_enabled'],
            'engagement_enabled' => (bool) $validated['engagement_enabled'],
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, bool>
     */
    private function enabledMap(array $section): array
    {
        /** @var array<string, mixed> $enabled */
        $enabled = $section['enabled'] ?? [];

        return array_map(static fn (mixed $value): bool => (bool) $value, $enabled);
    }

    /**
     * Every remaining key after removing `enabled` is a per-platform interval.
     * This relies on Laravel's `excludeUnvalidatedArrayKeys` default (on here):
     * `validated()` strips any key without a rule, so only ruled platform
     * intervals survive — no unvalidated/injected key can leak into storage.
     *
     * @param  array<string, mixed>  $section
     * @return array<string, int>
     */
    private function minutes(array $section): array
    {
        unset($section['enabled']);

        return array_map(static fn (mixed $value): int => (int) $value, $section);
    }
}
