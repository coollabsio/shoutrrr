<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class SignConversationVideoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('conversation') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['content_type' => ['required', 'string', 'in:video/mp4']];
    }
}
