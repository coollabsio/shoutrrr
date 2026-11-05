<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\UiMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUiModeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(UiMode::class)],
        ];
    }
}
