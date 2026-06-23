<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'base_text' => ['present', 'nullable', 'string'],
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'set', 'account', 'accounts'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
            'destination.ids' => ['array', 'required_if:destination.kind,accounts'],
            'destination.ids.*' => ['string'],
            'targets' => ['array'],
            'targets.*.connected_account_id' => ['required', 'string'],
            'targets.*.auto_split' => ['boolean'],
            'targets.*.content_override' => ['nullable', 'array'],
            'targets.*.content_override.text' => ['nullable', 'string'],
            'targets.*.content_override.media_ids' => ['array'],
            'targets.*.content_override.media_ids.*' => ['string'],
            'media_ids' => ['array'],
            'media_ids.*' => ['string'],
            'expected_updated_at' => ['nullable', 'string'],
        ];
    }
}
