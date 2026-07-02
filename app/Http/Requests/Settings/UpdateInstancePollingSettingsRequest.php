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
            'engagement.x' => ['required', 'integer', 'min:5', 'max:10080'],
            'engagement.bluesky' => ['required', 'integer', 'min:5', 'max:10080'],
            'engagement.linkedin' => ['required', 'integer', 'min:5', 'max:10080'],
            'post_metrics' => ['required', 'array'],
            'post_metrics.x' => ['required', 'integer', 'min:5', 'max:10080'],
            'post_metrics.bluesky' => ['required', 'integer', 'min:5', 'max:10080'],
            'post_metrics.linkedin' => ['required', 'integer', 'min:5', 'max:10080'],
            'account_metrics' => ['required', 'array'],
            'account_metrics.x' => ['required', 'integer', 'min:5', 'max:10080'],
            'account_metrics.bluesky' => ['required', 'integer', 'min:5', 'max:10080'],
            'account_metrics.linkedin' => ['required', 'integer', 'min:5', 'max:10080'],
        ];
    }

    /**
     * @return array{
     *     engagement_poll_interval_minutes: array{x: int, bluesky: int, linkedin: int},
     *     post_metrics_poll_interval_minutes: array{x: int, bluesky: int, linkedin: int},
     *     account_metrics_poll_interval_minutes: array{x: int, bluesky: int, linkedin: int}
     * }
     */
    public function instancePollingSettings(): array
    {
        /** @var array{
         *     engagement: array{x: int, bluesky: int, linkedin: int},
         *     post_metrics: array{x: int, bluesky: int, linkedin: int},
         *     account_metrics: array{x: int, bluesky: int, linkedin: int}
         * } $settings
         */
        $settings = $this->validated();

        return [
            'engagement_poll_interval_minutes' => $settings['engagement'],
            'post_metrics_poll_interval_minutes' => $settings['post_metrics'],
            'account_metrics_poll_interval_minutes' => $settings['account_metrics'],
        ];
    }
}
