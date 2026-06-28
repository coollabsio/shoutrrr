import type { PlatformName } from '@/types/compose';

/**
 * Pure, DOM-free mirror of the server-side `PostSplitter` packing algorithm.
 * The composer's inline thread markers and the server publish path MUST agree on
 * where one section ends and the next begins; keeping the logic here (free of
 * tiptap/ProseMirror imports) lets a parity test exercise it against the PHP
 * implementation. See `tests/Fixtures/post-splitter-parity.json`.
 */

/**
 * Client-side mirror of the active platform's length measure. X counts UTF-16
 * code units (JS string length); the others approximate with code points. The
 * server's grapheme/byte count remains authoritative.
 */
export function measure(text: string, platform: PlatformName): number {
    // oxlint-disable-next-line no-misused-spread -- intentional code-point count
    return platform === 'x' ? text.length : [...text].length;
}

export interface Section {
    /** Indices (into the input paragraphs) of the paragraphs in this section. */
    paraIndices: number[];
    /** The section's text — its paragraphs joined by a single newline. */
    text: string;
}

/**
 * Paragraph-aware greedy split. Each paragraph either joins the current section
 * (when `current + "\n" + para` fits in `limit`) or starts a fresh one. A
 * paragraph that alone exceeds `limit` becomes a single overflowing section — we
 * never split mid-paragraph, so markers always sit on paragraph boundaries.
 *
 * Paragraphs are joined with a single "\n" to match the canonical base text
 * (blocks serialize newline-separated) and the server-side `PostSplitter`, so
 * the preview's section boundaries are exactly what gets published.
 */
export function packSections(
    paragraphs: string[],
    platform: PlatformName,
    limit: number,
): Section[] {
    const sections: Section[] = [];

    let cur = '';
    let curParas: number[] = [];

    for (let i = 0; i < paragraphs.length; i++) {
        const para = paragraphs[i] ?? '';
        const joined = cur === '' ? para : `${cur}\n${para}`;

        if (cur === '' || measure(joined, platform) <= limit) {
            cur = joined;
            curParas.push(i);

            continue;
        }

        sections.push({ paraIndices: curParas, text: cur });
        cur = para;
        curParas = [i];
    }

    sections.push({ paraIndices: curParas, text: cur });

    return sections;
}

/**
 * Split the structured author segments into the section strings the composer
 * previews — the TS analogue of the server `PostSplitter::split(...).sections`.
 * Each segment is trimmed; empties are dropped (falling back to one empty
 * section), then packed per platform limit.
 */
export function previewSections(
    segments: string[],
    platform: PlatformName,
    limit: number,
): string[] {
    const clean = segments
        .map((segment) => segment.trim())
        .filter((segment) => segment !== '');
    const use = clean.length > 0 ? clean : [''];

    return use.flatMap((segment) =>
        packSections(segment.split('\n'), platform, limit).map(
            (section) => section.text,
        ),
    );
}
