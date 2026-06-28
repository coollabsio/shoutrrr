import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import type { PlatformName } from '@/types/compose';

import { previewSections } from '../section-split';

interface ParityCase {
    name: string;
    platform: PlatformName;
    limit: number;
    paragraphs: { char: string; len: number }[];
    expected: number[][];
}

/**
 * Parity guard: the server-side `PostSplitter` and the composer preview run the
 * SAME paragraph-packing algorithm in two languages. They must agree on section
 * boundaries or the published thread drifts from what the user saw. The Pest
 * suite (`tests/Unit/Posts/PostSplitterTest.php`) asserts the identical cases.
 */
const cases = JSON.parse(
    readFileSync(
        resolve(process.cwd(), 'tests/Fixtures/post-splitter-parity.json'),
        'utf8',
    ),
) as ParityCase[];

describe('section boundaries match the server splitter fixture', () => {
    it.each(cases.map((c) => [c.name, c] as const))('%s', (_name, testCase) => {
        const paragraphs = testCase.paragraphs.map((p) => p.char.repeat(p.len));
        const text = paragraphs.join('\n');

        const expected = testCase.expected.map((group) =>
            group.map((i) => paragraphs[i]).join('\n'),
        );

        expect(
            previewSections([text], testCase.platform, testCase.limit),
        ).toEqual(expected);
    });
});

it('emits one section per non-empty segment', () => {
    expect(previewSections(['first post', 'second post'], 'x', 280)).toEqual([
        'first post',
        'second post',
    ]);
});

it('auto-splits each segment independently', () => {
    const long = 'a'.repeat(200);

    expect(previewSections([`${long}\n${long}`, 'third'], 'x', 280)).toEqual([
        long,
        long,
        'third',
    ]);
});

it('treats a literal --- as section text, not a boundary', () => {
    expect(previewSections(['before\n---\nafter'], 'x', 280)).toEqual([
        'before\n---\nafter',
    ]);
});
