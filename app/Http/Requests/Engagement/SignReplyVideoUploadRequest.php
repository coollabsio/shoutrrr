<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

class SignReplyVideoUploadRequest extends FormRequest
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
        return ['content_type' => ['required', 'string', 'in:video/mp4']];
    }
}
