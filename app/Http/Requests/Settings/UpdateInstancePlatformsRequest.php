<?php

namespace App\Http\Requests\Settings;

use App\Enums\Platform;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInstancePlatformsRequest extends FormRequest
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
        $rules = [
            'platforms' => ['required', 'array'],
            'linkedin_community_management_enabled' => ['required', 'boolean'],
        ];

        foreach (Platform::cases() as $platform) {
            $rules["platforms.{$platform->value}"] = ['required', 'boolean'];
        }

        return $rules;
    }

    public function linkedinCommunityManagementEnabled(): bool
    {
        return (bool) $this->validated('linkedin_community_management_enabled');
    }

    /**
     * @return array<string, bool>
     */
    public function platformsEnabled(): array
    {
        /** @var array{platforms: array<string, bool>} $validated */
        $validated = $this->validated();

        return $validated['platforms'];
    }
}
