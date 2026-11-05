import { describe, expect, it } from 'vitest';

import { sidebarContextFor } from '../sidebar-context';

describe('sidebarContextFor', () => {
    it('keeps home and top-level lists on the main menu', () => {
        expect(sidebarContextFor('/agent', { classicMode: false })).toBeNull();
        expect(
            sidebarContextFor('/dashboard', { classicMode: true }),
        ).toBeNull();
        expect(sidebarContextFor('/posts', { classicMode: true })).toBeNull();
        expect(
            sidebarContextFor('/posts/next-slot', { classicMode: true }),
        ).toBeNull();
        expect(
            sidebarContextFor('/calendar/2026-10', { classicMode: true }),
        ).toBeNull();
        expect(
            sidebarContextFor('/accounts', { classicMode: true }),
        ).toBeNull();
        expect(sidebarContextFor('/sync', { classicMode: true })).toBeNull();
    });

    it('swaps in settings families from either mode', () => {
        expect(
            sidebarContextFor('/settings/appearance', { classicMode: false })
                ?.id,
        ).toBe('account');
        expect(
            sidebarContextFor('/settings/workspace/members', {
                classicMode: true,
            })?.label,
        ).toBe('Workspace');
        expect(
            sidebarContextFor('/settings/instance/polling', {
                classicMode: false,
            })?.id,
        ).toBe('instance');
    });

    it('swaps a post only in Classic', () => {
        expect(
            sidebarContextFor('/posts/01hpost', { classicMode: true }),
        ).toMatchObject({ id: 'post', postId: '01hpost', label: 'Post' });
        expect(
            sidebarContextFor('/posts/01hpost', { classicMode: false }),
        ).toBeNull();
    });

    it('swaps account wizards without opening Classic chrome', () => {
        expect(
            sidebarContextFor('/accounts/callback/meta', {
                classicMode: false,
            })?.id,
        ).toBe('accounts');
    });
});
