<?php

declare(strict_types=1);

namespace App\Support\Posts;

use App\Models\PostTarget;
use Illuminate\Support\Facades\DB;

/**
 * One-time migration helper: parses the retired `---` manual-break marker out of
 * legacy `base_text` / `content_override.text` and into the structured
 * `posts.segments` array. Nothing in the live request path uses this — the
 * editor and splitter no longer understand `---`.
 */
final class LegacyManualBreaks
{
    /** A line containing exactly three hyphens marked a manual thread break. */
    private const MARKER = '/^\s*---\s*$/m';

    /**
     * @return list<string>
     */
    public static function segments(string $text): array
    {
        $parts = preg_split(self::MARKER, $text) ?: [$text];

        $segments = array_values(array_filter(
            array_map(static fn (string $p): string => trim($p), $parts),
            static fn (string $p): bool => $p !== '',
        ));

        return $segments === [] ? [''] : $segments;
    }

    /**
     * Backfill every post that has no segments yet: derive `segments` from
     * `base_text`, rewrite `base_text` without `---`, and convert each target's
     * `content_override.text` to `content_override.segments`. Idempotent — only
     * rows with an empty `segments` value are touched.
     */
    public static function backfillExistingPosts(): void
    {
        DB::table('posts')
            ->select('id', 'base_text')
            ->whereIn('segments', ['[]', ''])
            ->orWhereNull('segments')
            ->orderBy('id')
            ->each(function (object $row): void {
                $segments = self::segments((string) $row->base_text);

                DB::table('posts')->where('id', $row->id)->update([
                    'segments' => json_encode($segments, JSON_THROW_ON_ERROR),
                    'base_text' => implode("\n", $segments),
                ]);
            });

        PostTarget::withoutGlobalScopes()
            ->whereNotNull('content_override')
            ->get(['id', 'content_override'])
            ->each(function (PostTarget $target): void {
                $override = $target->content_override;
                if (! is_array($override) || ! array_key_exists('text', $override)) {
                    return;
                }

                $override['segments'] = self::segments((string) ($override['text'] ?? ''));
                unset($override['text']);

                $target->forceFill(['content_override' => $override])->save();
            });
    }
}
