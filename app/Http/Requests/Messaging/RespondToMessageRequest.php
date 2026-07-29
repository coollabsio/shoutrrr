<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondToMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Conversation $conversation */
        $conversation = $this->route('conversation');

        // Zero on Bluesky, so `max:0` is what rejects an attachment there.
        $maxMedia = $conversation->platform->maxDirectMessageMedia();

        return [
            'text' => ['required_without:media', 'nullable', 'string', 'max:1000'],
            'media' => ['sometimes', 'array', 'max:'.$maxMedia],
            'media.*' => ['string', Rule::exists('post_media', 'id')->where('workspace_id', $conversation->workspace_id)],
        ];
    }
}
