import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, it } from 'vitest';

const source = readFileSync(
    resolve(import.meta.dirname, '../app-sidebar-header.tsx'),
    'utf8',
);

it('keeps the notification bell and sidebar unread badges live', () => {
    // The badges ride on `shell` and the bell on `notifications`; the header is
    // the one component every app page renders, so both refresh from here.
    expect(source).toContain('useLivePropsPoll({ only: LIVE_CHROME_PROPS');
});

it('asks for the unread counts rather than the whole shell prop', () => {
    // Re-sending accounts, sets, and platform limits every minute is pure waste;
    // Inertia resolves and returns only the requested dot paths.
    expect(source).toContain("'shell.unreadReplies'");
    expect(source).toContain("'shell.unreadMessages'");
    expect(source).not.toContain("'shell',");
});
