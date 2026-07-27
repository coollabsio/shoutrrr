<?php

declare(strict_types=1);

namespace App\Http\Requests\Gifs;

use App\Services\Gifs\KlipyClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachGifRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'catalog' => ['required', 'string', Rule::in(KlipyClient::CATALOGS)],
            'slug' => ['required', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:200'],
            'variants' => ['required', 'array', 'min:1', 'max:12'],
            'variants.*.url' => ['required', 'url:https', 'max:2000'],
            'variants.*.mime' => ['required', 'string', Rule::in(['image/gif', 'image/webp', 'video/mp4'])],
            'variants.*.width' => ['required', 'integer', 'min:1', 'max:10000'],
            'variants.*.height' => ['required', 'integer', 'min:1', 'max:10000'],
            'variants.*.bytes' => ['nullable', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
        ];
    }
}
