import { describe, expect, it } from 'vitest';

import { collapsePlatformNewlines } from '../platform-newlines';

describe('collapsePlatformNewlines', () => {
    it('caps X at a single blank line', () => {
        expect(collapsePlatformNewlines('a\n\n\n\nb', 'x')).toBe('a\n\nb');
        expect(collapsePlatformNewlines('a\n\n\nb', 'x')).toBe('a\n\nb');
    });

    it('caps LinkedIn at a single blank line', () => {
        expect(collapsePlatformNewlines('a\n\n\n\n\nb', 'linkedin')).toBe(
            'a\n\nb',
        );
    });

    it('leaves a single newline and a single blank line untouched', () => {
        expect(collapsePlatformNewlines('a\nb', 'x')).toBe('a\nb');
        expect(collapsePlatformNewlines('a\n\nb', 'x')).toBe('a\n\nb');
        expect(collapsePlatformNewlines('a\nb', 'linkedin')).toBe('a\nb');
    });

    it('preserves every newline on Bluesky', () => {
        expect(collapsePlatformNewlines('a\n\n\n\n\nb', 'bluesky')).toBe(
            'a\n\n\n\n\nb',
        );
    });

    it('collapses several separate runs in one post', () => {
        expect(collapsePlatformNewlines('a\n\n\n\nb\n\n\n\nc', 'x')).toBe(
            'a\n\nb\n\nc',
        );
    });
});
