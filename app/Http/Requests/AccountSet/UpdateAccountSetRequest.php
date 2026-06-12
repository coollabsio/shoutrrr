<?php

declare(strict_types=1);

namespace App\Http\Requests\AccountSet;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('account_set'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'connected_account_ids' => ['array'],
            'connected_account_ids.*' => ['string'],
        ];
    }
}
