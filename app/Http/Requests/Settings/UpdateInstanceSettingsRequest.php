<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInstanceSettingsRequest extends FormRequest
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
            'registrations_enabled' => ['required', 'boolean'],
            'workspace_creation_enabled' => ['required', 'boolean'],
            'usage_tracking_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{registrations_enabled: bool, workspace_creation_enabled: bool, usage_tracking_enabled: bool}
     */
    public function instanceSettings(): array
    {
        /** @var array{registrations_enabled: bool, workspace_creation_enabled: bool, usage_tracking_enabled: bool} $settings */
        $settings = $this->validated();

        if (! (bool) config('kit.workspaces.enabled')) {
            $settings['workspace_creation_enabled'] = false;
        }

        return $settings;
    }
}
