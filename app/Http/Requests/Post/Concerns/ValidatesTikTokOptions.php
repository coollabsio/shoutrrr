<?php

declare(strict_types=1);

namespace App\Http\Requests\Post\Concerns;

use App\Enums\Platform;
use App\Enums\TikTokPostMode;
use App\Enums\TikTokPrivacyLevel;
use App\Models\ConnectedAccount;
use Illuminate\Validation\Rule;

/**
 * Server-side validation of the per-target `tiktok` options shared by the web
 * composer and the public API. Field-level rules allow-list every value; the
 * cross-field checks enforce TikTok's Content Sharing Guidelines so a post that
 * TikTok would reject can never be saved, whatever the client sent.
 */
trait ValidatesTikTokOptions
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function tikTokOptionRules(): array
    {
        return [
            'targets.*.tiktok' => ['sometimes', 'array'],
            'targets.*.tiktok.post_mode' => ['required_with:targets.*.tiktok', Rule::enum(TikTokPostMode::class)],
            'targets.*.tiktok.privacy_level' => ['nullable', Rule::enum(TikTokPrivacyLevel::class)],
            'targets.*.tiktok.allow_comment' => ['boolean'],
            'targets.*.tiktok.allow_duet' => ['boolean'],
            'targets.*.tiktok.allow_stitch' => ['boolean'],
            'targets.*.tiktok.disclose_commercial' => ['boolean'],
            'targets.*.tiktok.brand_organic' => ['boolean'],
            'targets.*.tiktok.branded_content' => ['boolean'],
        ];
    }

    /**
     * Cross-field problems in the targets' TikTok options, keyed by field path.
     *
     * @param  array<array-key, mixed>  $targets
     * @return array<string, string>
     */
    protected function tikTokOptionErrors(array $targets): array
    {
        $withOptions = array_filter(
            $targets,
            static fn (mixed $target): bool => is_array($target) && is_array($target['tiktok'] ?? null),
        );

        if ($withOptions === []) {
            return [];
        }

        $platforms = ConnectedAccount::query()
            ->whereKey(array_column($withOptions, 'connected_account_id'))
            ->pluck('platform', 'id');
        $errors = [];

        foreach ($withOptions as $index => $target) {
            $options = $target['tiktok'];
            $prefix = "targets.{$index}.tiktok";

            if ($platforms->get($target['connected_account_id'] ?? '') !== Platform::TikTok) {
                $errors[$prefix] = 'TikTok options can only be set on a TikTok account.';

                continue;
            }

            $brandOrganic = filter_var($options['brand_organic'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $brandedContent = filter_var($options['branded_content'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (($brandOrganic || $brandedContent) && ! filter_var($options['disclose_commercial'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $errors["{$prefix}.disclose_commercial"] = 'Turn on content disclosure to mark this post as promoting your brand or branded content.';
            }

            if ($brandedContent && ($options['privacy_level'] ?? null) === TikTokPrivacyLevel::SelfOnly->value) {
                $errors["{$prefix}.privacy_level"] = 'Branded content visibility cannot be set to private.';
            }
        }

        return $errors;
    }
}
