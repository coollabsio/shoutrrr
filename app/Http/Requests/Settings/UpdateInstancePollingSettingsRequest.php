<?php

namespace App\Http\Requests\Settings;

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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'engagement' => ['required', 'array'],
            'engagement.enabled' => ['required', 'array'],
            'engagement.enabled.x' => ['required', 'boolean'],
            'engagement.enabled.bluesky' => ['required', 'boolean'],
            'engagement.enabled.linkedin' => ['required', 'boolean'],
            'engagement.x' => ['required', 'integer', 'min:5', 'max:10080'],
            'engagement.bluesky' => ['required', 'integer', 'min:5', 'max:10080'],
            'engagement.linkedin' => ['required', 'integer', 'min:5', 'max:10080'],
            'post_metrics' => ['required', 'array'],
            'post_metrics.enabled' => ['required', 'array'],
            'post_metrics.enabled.x' => ['required', 'boolean'],
            'post_metrics.enabled.bluesky' => ['required', 'boolean'],
            'post_metrics.enabled.linkedin' => ['required', 'boolean'],
            'post_metrics.x' => ['required', 'integer', 'min:5', 'max:10080'],
            'post_metrics.bluesky' => ['required', 'integer', 'min:5', 'max:10080'],
            'post_metrics.linkedin' => ['required', 'integer', 'min:5', 'max:10080'],
            'account_metrics' => ['required', 'array'],
            'account_metrics.enabled' => ['required', 'array'],
            'account_metrics.enabled.x' => ['required', 'boolean'],
            'account_metrics.enabled.bluesky' => ['required', 'boolean'],
            'account_metrics.enabled.linkedin' => ['required', 'boolean'],
            'account_metrics.x' => ['required', 'integer', 'min:5', 'max:10080'],
            'account_metrics.bluesky' => ['required', 'integer', 'min:5', 'max:10080'],
            'account_metrics.linkedin' => ['required', 'integer', 'min:5', 'max:10080'],
        ];
    }

    /**
     * @return array{
     *     engagement_polling_enabled: array{x: bool, bluesky: bool, linkedin: bool},
     *     post_metrics_polling_enabled: array{x: bool, bluesky: bool, linkedin: bool},
     *     account_metrics_polling_enabled: array{x: bool, bluesky: bool, linkedin: bool},
     *     engagement_poll_interval_minutes: array{x: int, bluesky: int, linkedin: int},
     *     post_metrics_poll_interval_minutes: array{x: int, bluesky: int, linkedin: int},
     *     account_metrics_poll_interval_minutes: array{x: int, bluesky: int, linkedin: int}
     * }
     */
    public function instancePollingSettings(): array
    {
        /** @var array{
         *     engagement: array{enabled: array{x: bool, bluesky: bool, linkedin: bool}, x: int, bluesky: int, linkedin: int},
         *     post_metrics: array{enabled: array{x: bool, bluesky: bool, linkedin: bool}, x: int, bluesky: int, linkedin: int},
         *     account_metrics: array{enabled: array{x: bool, bluesky: bool, linkedin: bool}, x: int, bluesky: int, linkedin: int}
         * } $settings
         */
        $settings = $this->validated();

        return [
            'engagement_polling_enabled' => $settings['engagement']['enabled'],
            'post_metrics_polling_enabled' => $settings['post_metrics']['enabled'],
            'account_metrics_polling_enabled' => $settings['account_metrics']['enabled'],
            'engagement_poll_interval_minutes' => $this->minutes($settings['engagement']),
            'post_metrics_poll_interval_minutes' => $this->minutes($settings['post_metrics']),
            'account_metrics_poll_interval_minutes' => $this->minutes($settings['account_metrics']),
        ];
    }

    /**
     * @param  array{enabled: array{x: bool, bluesky: bool, linkedin: bool}, x: int, bluesky: int, linkedin: int}  $settings
     * @return array{x: int, bluesky: int, linkedin: int}
     */
    private function minutes(array $settings): array
    {
        return [
            'x' => $settings['x'],
            'bluesky' => $settings['bluesky'],
            'linkedin' => $settings['linkedin'],
        ];
    }
}
