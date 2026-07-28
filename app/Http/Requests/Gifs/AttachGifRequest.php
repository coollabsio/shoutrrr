<?php

declare(strict_types=1);

namespace App\Http\Requests\Gifs;

use App\Services\Gifs\KlipyClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachGifRequest extends FormRequest
{
    /**
     * Mirrors the two upload requests this endpoint pair replaces:
     * StorePostMediaRequest checks `can('update', post)`, StoreReplyMediaRequest
     * treats a resolved {reply} binding as sufficient (it is already scoped to
     * the authed user's workspace, 404ing otherwise). Same route, same request
     * class, so branch on whichever binding is present.
     */
    public function authorize(): bool
    {
        $post = $this->route('post');
        if ($post !== null) {
            return $this->user()->can('update', $post);
        }

        return $this->route('reply') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'catalog' => ['required', 'string', Rule::in(KlipyClient::CATALOGS)],
            'slug' => ['required', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:200'],
            'variants' => ['required', 'array', 'min:1', 'max:12'],
            'variants.*.url' => ['required', 'url:https', 'max:2000'],
            'variants.*.mime' => ['required', 'string', Rule::in(['image/gif', 'image/webp', 'video/mp4'])],
            'variants.*.width' => ['required', 'integer', 'min:1', 'max:10000'],
            'variants.*.height' => ['required', 'integer', 'min:1', 'max:10000'],
            'variants.*.bytes' => ['nullable', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            // What the client currently holds attached, used as the "existing
            // media" set for GifAttacher's mixing-rule guard. Both surfaces need
            // it: a reply has no reply_id column on post_media at all, and a
            // post's media rows stay orphaned until the draft is next saved.
            // Both controllers re-resolve these ids workspace-scoped, so they
            // grant no access the caller doesn't already have.
            'media_ids' => ['nullable', 'array', 'max:10'],
            'media_ids.*' => ['string'],
        ];
    }
}
