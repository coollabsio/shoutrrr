import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import type { Account } from '@/types/compose';

import { visiblePlatformTabAccounts } from '../platform-tabs';

function account(id: string): Account {
    return {
        id,
        platform: 'x',
        handle: `@${id}`,
        display_name: id,
        avatar_url: null,
        max_text_length: 280,
        x_premium: false,
    };
}

describe('platform tabs overflow', () => {
    it('keeps four accounts visible before showing the overflow trigger', () => {
        const accounts = ['one', 'two', 'three', 'four', 'five'].map(account);

        const { visibleAccounts, overflowAccounts } =
            visiblePlatformTabAccounts(accounts, 'three');

        expect(visibleAccounts.map((item) => item.id)).toEqual([
            'one',
            'two',
            'three',
            'four',
        ]);
        expect(overflowAccounts.map((item) => item.id)).toEqual(['five']);
    });

    it('keeps the active account visible when extra accounts collapse', () => {
        const accounts = ['one', 'two', 'three', 'four', 'five'].map(account);

        const { visibleAccounts, overflowAccounts } =
            visiblePlatformTabAccounts(accounts, 'five');

        expect(visibleAccounts.map((item) => item.id)).toEqual([
            'one',
            'two',
            'three',
            'five',
        ]);
        expect(overflowAccounts.map((item) => item.id)).toEqual(['four']);
    });

    it('shows all accounts when the tab list fits the visible limit', () => {
        const accounts = ['one', 'two', 'three', 'four'].map(account);

        const { visibleAccounts, overflowAccounts } =
            visiblePlatformTabAccounts(accounts, 'two');

        expect(visibleAccounts.map((item) => item.id)).toEqual([
            'one',
            'two',
            'three',
            'four',
        ]);
        expect(overflowAccounts).toEqual([]);
    });

    it('keeps two accounts visible for the mobile tab limit', () => {
        const accounts = ['one', 'two', 'three'].map(account);

        const { visibleAccounts, overflowAccounts } =
            visiblePlatformTabAccounts(accounts, 'one', 2);

        expect(visibleAccounts.map((item) => item.id)).toEqual(['one', 'two']);
        expect(overflowAccounts.map((item) => item.id)).toEqual(['three']);
    });

    it('keeps the active account visible with the mobile tab limit', () => {
        const accounts = ['one', 'two', 'three'].map(account);

        const { visibleAccounts, overflowAccounts } =
            visiblePlatformTabAccounts(accounts, 'three', 2);

        expect(visibleAccounts.map((item) => item.id)).toEqual([
            'one',
            'three',
        ]);
        expect(overflowAccounts.map((item) => item.id)).toEqual(['two']);
    });

    it('renders a two-account mobile tab row and a larger desktop tab row', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/components/compose/platform-tabs.tsx',
            ),
            'utf8',
        );

        expect(source).toContain(
            'visiblePlatformTabAccounts(accounts, activeTab, 2)',
        );
        expect(source).toContain('md:hidden');
        expect(source).toContain('hidden md:flex');
        expect(source).toContain('compact');
        expect(source).toContain('min-w-0 flex-1 basis-0');
        expect(source).toContain('+{overflowAccounts.length} more');
    });
});
