<?php

declare(strict_types=1);

namespace App\Http\Requests\Engagement;

use App\Models\PostTargetReply;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        // maxMedia() is the composer's per-post limit and says nothing about
        // comments. Without this, LinkedIn/Meta/Threads accept an attachment
        // here and only reject it once SendReply reaches the connector, leaving
        // a failed row instead of a validation error.
        $maxMedia = $reply->platform->supportsReplyMedia() ? $reply->platform->maxMedia() : 0;

        return [
            'text' => ['required_without:media', 'nullable', 'string', 'max:'.$max],
            'media' => ['sometimes', 'array', 'max:'.$maxMedia],
            'media.*' => ['string', Rule::exists('post_media', 'id')->where('workspace_id', $reply->workspace_id)],
        ];
    }
}
