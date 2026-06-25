<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use App\Models\PostTargetReply;
use Illuminate\Foundation\Http\FormRequest;

class RespondToReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var PostTargetReply $reply */
        $reply = $this->route('reply');
        $max = $reply->target?->account?->maxTextLength() ?? $reply->platform->maxLength();

        return [
            'text' => ['required', 'string', 'max:'.$max],
            'media' => ['sometimes', 'array', 'max:'.$reply->platform->maxMedia()],
            'media.*' => ['string', 'exists:post_media,id'],
        ];
    }
}
