<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversationMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The {conversation} binding is already scoped to the user's workspace
        // (404s otherwise), so reaching here means the conversation belongs to
        // the user. The platform check is EnsureConversationSupportsDirectMessageMedia's.
        return $this->route('conversation') !== null;
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
