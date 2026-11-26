<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use App\Enums\PostFormat;
use App\Http\Requests\Post\Concerns\DerivesMentionHandleRules;
use App\Http\Requests\Post\Concerns\ValidatesTikTokOptions;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePostRequest extends FormRequest
{
    use DerivesMentionHandleRules, ValidatesTikTokOptions;

    public function authorize(): bool
    {
        return $this->user()->can('create', Post::class);
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
            // Per-account choices made before the draft exists (a format, TikTok's
            // post settings) ride along on the first save so they are not lost.
            'targets' => ['array'],
            'targets.*.connected_account_id' => ['required', 'string'],
            'targets.*.format' => ['nullable', Rule::enum(PostFormat::class)],
            ...$this->tikTokOptionRules(),
            'auto_repost' => ['sometimes', 'nullable', 'boolean'],
            'skip_sync' => ['sometimes', 'boolean'],
            'segment_breaks' => ['array'],
            'segment_breaks.*' => ['string'],
            'placements' => ['array'],
            'placements.*.media_id' => ['required', 'string'],
            'placements.*.segment_ref' => ['required', 'string'],
            'placements.*.position' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $targets = $this->input('targets');

                foreach ($this->tikTokOptionErrors(is_array($targets) ? $targets : []) as $key => $message) {
                    $validator->errors()->add($key, $message);
                }
            },
        ];
    }
}
