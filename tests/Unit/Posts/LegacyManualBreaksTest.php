<?php

use App\Support\Posts\LegacyManualBreaks;

test('splits text on --- lines into trimmed, non-empty segments', function () {
    expect(LegacyManualBreaks::segments("first part\n---\nsecond part"))
        ->toBe(['first part', 'second part']);
});

test('keeps paragraph newlines inside a segment', function () {
    expect(LegacyManualBreaks::segments("a\nb\n---\nc"))
        ->toBe(["a\nb", 'c']);
});

test('text with no marker is a single segment', function () {
    expect(LegacyManualBreaks::segments('just one post'))
        ->toBe(['just one post']);
});

test('empty text yields a single empty segment', function () {
    expect(LegacyManualBreaks::segments(''))->toBe(['']);
});
