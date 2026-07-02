<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

abstract class AbstractImageEditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    /**
     * Decode the JSON-encoded settings field (sent as a string in the multipart body).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->settings)) {
            $this->merge(['settings' => json_decode($this->settings, true)]);
        }
    }

    /**
     * Validation shared by the create and update paths: the composed PNG plus the
     * full edit-settings schema. Subclasses merge their own rules (e.g. `source`).
     *
     * @return array<string, mixed>
     */
    protected function baseRules(): array
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
            'settings.zoom' => ['required', 'numeric'],
            'settings.tilt' => ['required', 'array'],
            'settings.crop' => ['nullable', 'array'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
