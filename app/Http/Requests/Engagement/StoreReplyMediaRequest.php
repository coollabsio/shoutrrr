<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use Illuminate\Foundation\Http\FormRequest;

class StoreReplyMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The {reply} binding is already scoped to the user's workspace (404s
        // otherwise), so reaching here means the reply belongs to the user.
        return $this->route('reply') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:8192'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
