<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePostScreenshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->settings)) {
            $this->merge(['settings' => json_decode($this->settings, true)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'composed' => ['required', 'file', 'mimetypes:image/png', 'max:8192'],
            'settings' => ['required', 'array'],
            'settings.version' => ['required', 'integer'],
            'settings.background' => ['required', 'array'],
            'settings.padding' => ['required', 'numeric'],
            'settings.radius' => ['required', 'numeric'],
            'settings.shadow' => ['required', 'string'],
            'settings.aspect' => ['required', 'string'],
            'settings.tilt' => ['required', 'array'],
            'settings.crop' => ['nullable', 'array'],
        ];
    }
}
