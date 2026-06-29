<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuggestReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tone' => ['nullable', 'string', Rule::in(['friendly', 'professional', 'brief'])],
            'post_excerpt' => ['nullable', 'string', 'max:5000'],
            'limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
