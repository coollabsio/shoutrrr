<?php

declare(strict_types=1);

namespace App\Http\Requests\Post\Concerns;

use App\Enums\Platform;

trait DerivesMentionHandleRules
{
    /**
     * Validation rules for every `mentions.*.handles.*` key.
     *
     * Derived from the Platform enum (plus the non-platform `linkedin_urn`)
     * rather than hard-coded, because Laravel's `excludeUnvalidatedArrayKeys`
     * strips any nested `handles.*` key that lacks an explicit rule from
     * `validated()` — which silently dropped Discord/Meta mention markup before
     * it reached DraftService (mirrors the fix in WorkspaceMentionController).
     *
     * @return array<string, array<int, string>>
     */
    protected function mentionHandleRules(): array
    {
        $rules = [];

        foreach (Platform::cases() as $platform) {
            $rules['mentions.*.handles.'.$platform->value] = ['nullable', 'string'];
        }

        $rules['mentions.*.handles.linkedin_urn'] = ['nullable', 'string', 'max:255'];

        return $rules;
    }
}
