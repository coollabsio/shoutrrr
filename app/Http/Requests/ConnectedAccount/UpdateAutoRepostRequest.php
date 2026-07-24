<?php

declare(strict_types=1);

namespace App\Http\Requests\ConnectedAccount;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAutoRepostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('account')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'min_percentile' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
