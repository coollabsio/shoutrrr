import { expect, test } from 'vitest';

import { windowLabel } from './helpers';

test('windowLabel returns null when no window', () => {
    expect(windowLabel(null)).toBeNull();
});

test('windowLabel warns when window expired', () => {
    expect(windowLabel(new Date(Date.now() - 1000).toISOString())).toMatch(
        /closed/i,
    );
});

test('windowLabel counts down when window is still open', () => {
    const inTwoHours = new Date(Date.now() + 2 * 60 * 60 * 1000).toISOString();

    expect(windowLabel(inTwoHours)).toMatch(/closes in 2h/i);
});
