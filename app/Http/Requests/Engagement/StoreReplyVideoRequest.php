<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

class StoreReplyVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('reply') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string'],
            'duration_seconds' => ['required', 'integer', 'min:1'],
            'width' => ['required', 'integer', 'min:1'],
            'height' => ['required', 'integer', 'min:1'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
