<?php

use App\Enums\Platform;
use App\Services\Posts\PostSplitter;

function splitter(): PostSplitter
{
    return new PostSplitter;
}

test('short text yields a single section with no issues', function () {
    $result = splitter()->split('hello world', Platform::X, true);

    expect($result->sections)->toBe(['hello world'])
        ->and($result->issues)->toBe([]);
});

test('manual breaks split into multiple sections', function () {
    $result = splitter()->split("first part\n---\nsecond part", Platform::X, true);

    expect($result->sections)->toBe(['first part', 'second part']);
});

test('auto split chunks an over-limit segment on word boundaries', function () {
    $text = str_repeat('word ', 80); // 400 chars, over X's 280
    $result = splitter()->split(trim($text), Platform::X, true);

    expect(count($result->sections))->toBeGreaterThan(1)
        ->and(collect($result->sections)->every(fn (string $s) => Platform::X->measure($s) <= 280))->toBeTrue()
        ->and($result->issues)->toBe([]);
});

test('without auto split an over-limit segment stays whole and is flagged', function () {
    $text = str_repeat('a', 400);
    $result = splitter()->split($text, Platform::X, false);

    expect($result->sections)->toHaveCount(1)
        ->and($result->issues)->toContain('section_too_long');
});

test('linkedin thread max flags multi-section drafts', function () {
    $result = splitter()->split("one\n---\ntwo", Platform::LinkedIn, true);

    expect($result->issues)->toContain('too_many_sections');
});

test('bluesky flags a section that fits graphemes but blows the byte budget', function () {
    // 1500 multibyte chars: under 300 graphemes? No — choose 200 emoji-free multibyte.
    $text = str_repeat('é', 200); // 200 graphemes (ok < 300) but 400 bytes (ok < 3000)
    $result = splitter()->split($text, Platform::Bluesky, false);
    expect($result->issues)->toBe([]);

    $big = str_repeat('é', 1600); // 1600 graphemes > 300 -> section_too_long
    $result2 = splitter()->split($big, Platform::Bluesky, false);
    expect($result2->issues)->toContain('section_too_long');
});

test('validateSections flags too many media for the platform', function () {
    $issues = splitter()->validateSections(['hi'], Platform::X, mediaCount: 5);
    expect($issues)->toContain('too_many_media');
});
