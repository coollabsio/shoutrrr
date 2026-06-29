<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInstanceAiSettingsRequest extends FormRequest
{
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
        return [
            'ai_enabled' => ['required', 'boolean'],
            'ai_provider' => ['required', 'string', 'max:50'],
            'ai_model' => ['required', 'string', 'max:120'],
            'ai_api_key' => ['nullable', 'string', 'max:500'],
            'ai_clear_api_key' => ['boolean'],
        ];
    }

    /**
     * @return array{values: array{ai_enabled: bool, ai_provider: string, ai_model: string}, apiKey: ?string}
     */
    public function aiSettings(): array
    {
        $clear = (bool) $this->boolean('ai_clear_api_key');
        $key = (string) $this->string('ai_api_key');

        // null = leave unchanged, '' = clear, non-empty = set.
        $apiKey = $clear ? '' : ($key !== '' ? $key : null);

        return [
            'values' => [
                'ai_enabled' => $this->boolean('ai_enabled'),
                'ai_provider' => (string) $this->string('ai_provider'),
                'ai_model' => (string) $this->string('ai_model'),
            ],
            'apiKey' => $apiKey,
        ];
    }
}
