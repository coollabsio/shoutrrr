<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\PostMedia;

final class SegmentMediaResolver
{
    public const HEAD = '__head__';

    /**
     * @param  list<string>  $sections
     * @param  list<int>  $sectionSources
     * @param  list<string>  $segmentBreaks
     * @param  list<array{post_media_id: string, segment_ref: string, position: int}>  $placements
     * @param  list<PostMedia>  $allMedia
     * @return array<int, list<PostMedia>>
     */
    public function resolve(array $sections, array $sectionSources, array $segmentBreaks, array $placements, array $allMedia): array
    {
        if ($placements === []) {
            return [0 => $allMedia];
        }

        $mediaById = [];
        foreach ($allMedia as $media) {
            $mediaById[$media->id] = $media;
        }

        // segment_ref -> authored segment index.
        $indexByRef = [self::HEAD => 0];
        foreach ($segmentBreaks as $i => $breakId) {
            $indexByRef[$breakId] = $i + 1;
        }

        // authored segment index -> first section index that survived the split.
        $firstSectionForAuthored = [];
        foreach ($sectionSources as $sectionIndex => $authored) {
            if (! isset($firstSectionForAuthored[$authored])) {
                $firstSectionForAuthored[$authored] = $sectionIndex;
            }
        }

        $ordered = $placements;
        usort($ordered, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        $out = [];
        foreach ($ordered as $placement) {
            $media = $mediaById[$placement['post_media_id']] ?? null;
            if ($media === null) {
                continue;
            }
            $authored = $indexByRef[$placement['segment_ref']] ?? 0;
            $section = $firstSectionForAuthored[$authored] ?? $this->fallbackSection($authored, $firstSectionForAuthored);
            $out[$section][] = $media;
        }

        ksort($out);

        return $out;
    }

    /**
     * A media-bearing authored segment with no surviving section rides the
     * nearest earlier authored segment's section, else section 0.
     *
     * @param  array<int, int>  $firstSectionForAuthored
     */
    private function fallbackSection(int $authored, array $firstSectionForAuthored): int
    {
        for ($i = $authored - 1; $i >= 0; $i--) {
            if (isset($firstSectionForAuthored[$i])) {
                return $firstSectionForAuthored[$i];
            }
        }

        return 0;
    }
}
