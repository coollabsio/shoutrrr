<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

class StoreReplyImageEditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('reply') !== null;
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
            'settings.zoom' => ['required', 'numeric'],
            'settings.tilt' => ['required', 'array'],
            'settings.crop' => ['nullable', 'array'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
            'source' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:8192'],
        ];
    }
}
