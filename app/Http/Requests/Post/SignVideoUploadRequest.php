<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class SignVideoUploadRequest extends FormRequest
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
            'content_type' => ['required', 'string', 'in:video/mp4'],
        ];
    }
}
