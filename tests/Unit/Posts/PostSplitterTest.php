<?php

use App\Enums\Platform;
use App\Services\Posts\PostSplitter;

function splitter(): PostSplitter
{
    return new PostSplitter;
}

test('short text yields a single section with no issues', function () {
    $result = splitter()->split(['hello world'], Platform::X, true);

    expect($result->sections)->toBe(['hello world'])
        ->and($result->issues)->toBe([]);
});

test('each segment becomes its own section', function () {
    $result = splitter()->split(['first part', 'second part'], Platform::X, true);

    expect($result->sections)->toBe(['first part', 'second part']);
});

test('auto split chunks an over-limit segment on word boundaries', function () {
    $text = str_repeat('word ', 80); // 400 chars, over X's 280
    $result = splitter()->split([trim($text)], Platform::X, true);

    expect(count($result->sections))->toBeGreaterThan(1)
        ->and(collect($result->sections)->every(fn (string $s) => Platform::X->measure($s) <= 280))->toBeTrue()
        ->and($result->issues)->toBe([]);
});

test('auto split never merges a later paragraph into an earlier section', function () {
    // First paragraph fits on its own; the second alone also fits but the two
    // together exceed X's 280, so they must land in separate sections. The old
    // word-packer would pull leading words of paragraph two into section one.
    $first = str_repeat('a', 200);
    $second = str_repeat('b', 200);
    $result = splitter()->split(["{$first}\n{$second}"], Platform::X, true);

    expect($result->sections)->toBe([$first, $second])
        ->and($result->issues)->toBe([]);
});

test('auto split packs whole paragraphs greedily up to the limit', function () {
    // Three 120-char paragraphs on X (280): p1+p2 = 241 (+1 newline) fits, but
    // adding p3 overflows, so it threads into a second section.
    $p1 = str_repeat('a', 120);
    $p2 = str_repeat('b', 120);
    $p3 = str_repeat('c', 120);
    $result = splitter()->split(["{$p1}\n{$p2}\n{$p3}"], Platform::X, true);

    expect($result->sections)->toBe(["{$p1}\n{$p2}", $p3])
        ->and(collect($result->sections)->every(fn (string $s) => Platform::X->measure($s) <= 280))->toBeTrue();
});

test('auto split breaks a single over-limit paragraph on word boundaries', function () {
    $first = str_repeat('a', 100);
    $long = trim(str_repeat('word ', 80)); // 399 chars, over X's 280
    $result = splitter()->split(["{$first}\n{$long}"], Platform::X, true);

    expect($result->sections[0])->toBe($first)
        ->and(count($result->sections))->toBeGreaterThan(2)
        ->and(collect($result->sections)->every(fn (string $s) => Platform::X->measure($s) <= 280))->toBeTrue();
});

test('without auto split an over-limit segment stays whole and is flagged', function () {
    $text = str_repeat('a', 400);
    $result = splitter()->split([$text], Platform::X, false);

    expect($result->sections)->toHaveCount(1)
        ->and($result->issues)->toContain('section_too_long');
});

test('x can use a premium account length budget', function () {
    $text = str_repeat('a', 400);
    $result = splitter()->split([$text], Platform::X, true, maxLength: 25_000);

    expect($result->sections)->toBe([$text])
        ->and($result->issues)->toBe([]);
});

test('linkedin joins all segments into one section', function () {
    $result = splitter()->split(['one', 'two', 'three'], Platform::LinkedIn, true);

    expect($result->sections)->toBe(["one\ntwo\nthree"])
        ->and($result->issues)->not->toContain('too_many_sections');
});

test('bluesky flags a section that fits graphemes but blows the byte budget', function () {
    // 1500 multibyte chars: under 300 graphemes? No — choose 200 emoji-free multibyte.
    $text = str_repeat('é', 200); // 200 graphemes (ok < 300) but 400 bytes (ok < 3000)
    $result = splitter()->split([$text], Platform::Bluesky, false);
    expect($result->issues)->toBe([]);

    $big = str_repeat('é', 1600); // 1600 graphemes > 300 -> section_too_long
    $result2 = splitter()->split([$big], Platform::Bluesky, false);
    expect($result2->issues)->toContain('section_too_long');
});

test('validateSections flags too many media for the platform', function () {
    $issues = splitter()->validateSections(['hi'], Platform::X, mediaCount: 5);
    expect($issues)->toContain('too_many_media');
});

test('a literal --- inside a segment is content, not a break', function () {
    $result = splitter()->split(["before\n---\nafter"], Platform::X, false);

    expect($result->sections)->toBe(["before\n---\nafter"]);
});

/**
 * Parity guard: the composer preview (`section-split.ts`) and this splitter run
 * the SAME paragraph-packing algorithm in two languages. They must agree on
 * section boundaries or the published thread drifts from what the user saw. The
 * TypeScript suite asserts the identical cases against `previewSections`.
 *
 * @return list<array{name: string, platform: string, limit: int, paragraphs: list<array{char: string, len: int}>, expected: list<list<int>>}>
 */
function parityCases(): array
{
    $path = __DIR__.'/../../Fixtures/post-splitter-parity.json';

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

test('section boundaries match the composer preview fixture', function (array $case) {
    $platform = Platform::from($case['platform']);

    // The fixture's limit must equal the platform's own budget, otherwise the
    // PHP splitter (which reads maxLength()) and the JS preview would diverge.
    expect($case['limit'])->toBe($platform->maxLength());

    $paragraphs = array_map(
        static fn (array $p): string => str_repeat($p['char'], $p['len']),
        $case['paragraphs'],
    );

    $text = implode("\n", $paragraphs);

    $expected = array_map(
        static fn (array $group): string => implode("\n", array_map(
            static fn (int $i): string => $paragraphs[$i],
            $group,
        )),
        $case['expected'],
    );

    expect(splitter()->split([$text], $platform, true)->sections)->toBe($expected);
})->with(static fn (): array => collect(parityCases())->mapWithKeys(
    static fn (array $case): array => [$case['name'] => [$case]],
)->all());
