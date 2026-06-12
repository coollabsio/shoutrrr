<?php

declare(strict_types=1);

namespace App\Services\Posts;

use App\Enums\Platform;

class PostSplitter
{
    /**
     * A line containing exactly three hyphens marks a manual thread break.
     */
    private const MANUAL_BREAK = '/^\s*---\s*$/m';

    /**
     * Split text into platform sections and collect advisory validation issues.
     */
    public function split(string $text, Platform $platform, bool $autoSplit): SplitResult
    {
        $segments = $this->manualSegments($text);

        $sections = [];
        foreach ($segments as $segment) {
            if ($autoSplit) {
                foreach ($this->chunk($segment, $platform) as $chunk) {
                    $sections[] = $chunk;
                }
            } else {
                $sections[] = $segment;
            }
        }

        if ($sections === []) {
            $sections = [''];
        }

        return new SplitResult($sections, $this->validateSections($sections, $platform, 0));
    }

    /**
     * Recompute advisory issues for already-stored sections.
     *
     * @param  list<string>  $sections
     * @return list<string>
     */
    public function validateSections(array $sections, Platform $platform, int $mediaCount): array
    {
        $issues = [];

        foreach ($sections as $section) {
            if ($platform->measure($section) > $platform->maxLength()) {
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
     * @return list<string>
     */
    private function manualSegments(string $text): array
    {
        $parts = preg_split(self::MANUAL_BREAK, $text) ?: [$text];

        return array_values(array_filter(
            array_map(static fn (string $p): string => trim($p), $parts),
            static fn (string $p): bool => $p !== '',
        )) ?: [''];
    }

    /**
     * Greedily pack words into sections no longer than the platform limit.
     *
     * @return list<string>
     */
    private function chunk(string $segment, Platform $platform): array
    {
        if ($platform->measure($segment) <= $platform->maxLength()) {
            return [$segment];
        }

        $limit = $platform->maxLength();
        $words = preg_split('/\s+/', $segment) ?: [$segment];

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
                foreach ($this->hardSplit($word, $platform) as $piece) {
                    $chunks[] = $piece;
                }
            } else {
                $current = $word;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function hardSplit(string $word, Platform $platform): array
    {
        $limit = $platform->maxLength();
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
