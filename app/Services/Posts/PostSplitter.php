<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\Platform;

class PostSplitter
{
    /**
     * Split author segments into platform sections and collect advisory issues.
     *
     * Manual thread breaks are the array boundaries between `$segments` (the
     * structured author body) — there is no longer any in-text marker. Each
     * segment is trimmed; empty segments are dropped unless listed in
     * `$mediaSegments` (so a media-only segment still yields a section). For
     * thread-capped platforms (LinkedIn) all segments collapse into a single
     * section. `sectionSources` on the result maps each resolved section back
     * to the authored segment index it came from.
     *
     * @param  list<string>  $segments
     * @param  list<int>  $mediaSegments  Authored indices kept even when empty.
     */
    public function split(array $segments, Platform $platform, bool $autoSplit, ?int $maxLength = null, array $mediaSegments = []): SplitResult
    {
        $keep = array_flip($mediaSegments);

        // Keep authored index alongside trimmed text; drop empties unless the
        // segment carries media (so a media-only post survives).
        $kept = [];
        foreach ($segments as $index => $segment) {
            $text = trim($segment);
            if ($text !== '' || isset($keep[$index])) {
                $kept[] = ['index' => $index, 'text' => $text];
            }
        }
        if ($kept === []) {
            $kept = [['index' => 0, 'text' => '']];
        }

        if ($platform->threadMax() !== null) {
            $sections = [implode("\n", array_map(static fn (array $s): string => $s['text'], $kept))];

            return new SplitResult($sections, $this->validateSections($sections, $platform, 0, $maxLength), [0]);
        }

        $sections = [];
        $sources = [];
        foreach ($kept as $seg) {
            if ($autoSplit && $seg['text'] !== '') {
                foreach ($this->chunk($seg['text'], $platform, $maxLength) as $chunk) {
                    $sections[] = $chunk;
                    $sources[] = $seg['index'];
                }
            } else {
                $sections[] = $seg['text'];
                $sources[] = $seg['index'];
            }
        }

        if ($sections === []) {
            $sections = [''];
            $sources = [0];
        }

        return new SplitResult($sections, $this->validateSections($sections, $platform, 0, $maxLength), $sources);
    }

    /**
     * Recompute advisory issues for already-stored sections.
     *
     * @param  list<string>  $sections
     * @return list<string>
     */
    public function validateSections(array $sections, Platform $platform, int $mediaCount, ?int $maxLength = null): array
    {
        $issues = [];
        $limit = $maxLength ?? $platform->maxLength();

        foreach ($sections as $section) {
            if ($platform->measure($section) > $limit) {
                $issues[] = 'section_too_long';
                break;
            }
        }

        $maxBytes = $platform->maxBytes();
        if ($maxBytes !== null) {
            foreach ($sections as $section) {
                if (strlen($section) > $maxBytes) {
                    $issues[] = 'section_too_long';
                    break;
                }
            }
        }

        $threadMax = $platform->threadMax();
        if ($threadMax !== null && count($sections) > $threadMax) {
            $issues[] = 'too_many_sections';
        }

        if ($mediaCount > $platform->maxMedia()) {
            $issues[] = 'too_many_media';
        }

        return array_values(array_unique($issues));
    }

    /**
     * Pack whole paragraphs into sections no longer than the platform limit,
     * mirroring the composer's paragraph-aware preview (`deriveSectionMap`).
     *
     * Paragraphs (newline-separated blocks of the canonical base text) are the
     * atomic unit: a paragraph joins the current section while the joined text
     * still fits, otherwise it starts a fresh one. We never split mid-paragraph
     * here, so the published sections match the thread markers the user sees.
     * The one exception is a single paragraph that alone exceeds the limit — it
     * cannot be posted whole, so it is broken on word (then character)
     * boundaries.
     *
     * @return list<string>
     */
    private function chunk(string $segment, Platform $platform, ?int $maxLength): array
    {
        $limit = $maxLength ?? $platform->maxLength();

        if ($platform->measure($segment) <= $limit) {
            return [$segment];
        }

        $sections = [];
        $current = '';
        $hasCurrent = false;

        foreach (explode("\n", $segment) as $paragraph) {
            // A paragraph that cannot stand on its own is broken further. Flush
            // whatever is buffered first so section order is preserved.
            if ($platform->measure($paragraph) > $limit) {
                if ($hasCurrent) {
                    $sections[] = $current;
                    $current = '';
                    $hasCurrent = false;
                }

                foreach ($this->splitParagraph($paragraph, $platform, $limit) as $piece) {
                    $sections[] = $piece;
                }

                continue;
            }

            $candidate = $hasCurrent ? $current."\n".$paragraph : $paragraph;

            if (! $hasCurrent || $platform->measure($candidate) <= $limit) {
                $current = $candidate;
                $hasCurrent = true;

                continue;
            }

            // Adding this paragraph overflows the section — close it and start a
            // new one with the paragraph intact.
            $sections[] = $current;
            $current = $paragraph;
            $hasCurrent = true;
        }

        if ($hasCurrent) {
            $sections[] = $current;
        }

        return $sections === [] ? [''] : $sections;
    }

    /**
     * Break a single over-limit paragraph: greedily pack words, then hard-split
     * any single word that still exceeds the limit.
     *
     * @return list<string>
     */
    private function splitParagraph(string $paragraph, Platform $platform, int $limit): array
    {
        $words = preg_split('/\s+/', trim($paragraph)) ?: [$paragraph];

        $chunks = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($platform->measure($candidate) <= $limit) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
                $current = '';
            }

            // A single word longer than the limit is hard-split by characters.
            if ($platform->measure($word) > $limit) {
                foreach ($this->hardSplit($word, $platform, $limit) as $piece) {
                    $chunks[] = $piece;
                }
            } else {
                $current = $word;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [''] : $chunks;
    }

    /**
     * @return list<string>
     */
    private function hardSplit(string $word, Platform $platform, int $limit): array
    {
        $pieces = [];
        $buffer = '';

        foreach (mb_str_split($word) as $char) {
            if ($platform->measure($buffer.$char) > $limit) {
                $pieces[] = $buffer;
                $buffer = $char;

                continue;
            }
            $buffer .= $char;
        }

        if ($buffer !== '') {
            $pieces[] = $buffer;
        }

        return $pieces;
    }
}
