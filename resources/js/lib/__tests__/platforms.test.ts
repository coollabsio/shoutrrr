import { expect, it } from 'vitest';

import {
    disabledPlatformLabels,
    platformKeys,
    platformLabel,
} from '@/lib/platforms';

it('formats platform labels', () => {
    expect(platformLabel('x')).toBe('X');
    expect(platformLabel('bluesky')).toBe('Bluesky');
    expect(platformLabel('unknown')).toBe('unknown');
});

it('derives disabled platform labels from enabled keys', () => {
    const enabled = {
        x: false,
        bluesky: true,
        linkedin: false,
    };

    expect(platformKeys(enabled)).toEqual(['x', 'bluesky', 'linkedin']);
    expect(disabledPlatformLabels(enabled)).toEqual(['X', 'LinkedIn']);
});
