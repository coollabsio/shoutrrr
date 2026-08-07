<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use App\Enums\PostFormat;
use App\Http\Requests\Post\Concerns\DerivesMentionHandleRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    use DerivesMentionHandleRules;

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
            'base_text' => ['sometimes', 'nullable', 'string'],
            'segments' => ['present', 'array'],
            'segments.*' => ['nullable', 'string'],
            'mentions' => ['array'],
            'mentions.*.id' => ['required', 'string'],
            'mentions.*.label' => ['required', 'string'],
            'mentions.*.handles' => ['array'],
            ...$this->mentionHandleRules(),
            'destination' => ['required', 'array'],
            'destination.kind' => ['required', Rule::in(['all', 'none', 'set', 'account', 'accounts'])],
            'destination.id' => ['nullable', 'string', 'required_if:destination.kind,set,account'],
            'destination.ids' => ['array', 'required_if:destination.kind,accounts'],
            'destination.ids.*' => ['string'],
            'targets' => ['array'],
            'targets.*.connected_account_id' => ['required', 'string'],
            'targets.*.auto_split' => ['boolean'],
            'targets.*.format' => ['nullable', Rule::enum(PostFormat::class)],
            'targets.*.content_override' => ['nullable', 'array'],
            'targets.*.content_override.text' => ['nullable', 'string'],
            'targets.*.content_override.segments' => ['array'],
            'targets.*.content_override.segments.*' => ['nullable', 'string'],
            'targets.*.content_override.media_ids' => ['array'],
            'targets.*.content_override.media_ids.*' => ['string'],
            'targets.*.segment_breaks' => ['nullable', 'array'],
            'targets.*.segment_breaks.*' => ['string'],
            'targets.*.placements' => ['nullable', 'array'],
            'targets.*.placements.*.media_id' => ['required', 'string'],
            'targets.*.placements.*.segment_ref' => ['required', 'string'],
            'targets.*.placements.*.position' => ['required', 'integer'],
            'media_ids' => ['array'],
            'media_ids.*' => ['string'],
            'segment_breaks' => ['array'],
            'segment_breaks.*' => ['string'],
            'placements' => ['array'],
            'placements.*.media_id' => ['required', 'string'],
            'placements.*.segment_ref' => ['required', 'string'],
            'placements.*.position' => ['required', 'integer'],
            'auto_repost' => ['sometimes', 'nullable', 'boolean'],
            'expected_updated_at' => ['nullable', 'string'],
        ];
    }
}
