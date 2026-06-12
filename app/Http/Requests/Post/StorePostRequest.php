<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Post::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_text' => ['present', 'nullable', 'string'],
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'set', 'account'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
        ];
    }
}
