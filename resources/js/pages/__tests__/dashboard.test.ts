import { describe, expect, it } from 'vitest';

import {
    canManageConnectedAccounts,
    shouldShowDashboardNoAccountsNotice,
} from '@/lib/dashboard/accounts';

describe('canManageConnectedAccounts', () => {
    it('detects account management permission', () => {
        expect(canManageConnectedAccounts(['workspace.read'])).toBe(false);
        expect(
            canManageConnectedAccounts([
                'workspace.read',
                'workspace.accounts.manage',
            ]),
        ).toBe(true);
    });
});

describe('shouldShowDashboardNoAccountsNotice', () => {
    it('shows the no-account hint for read-only members only when no account exists', () => {
        expect(shouldShowDashboardNoAccountsNotice([], [])).toBe(false);
        expect(
            shouldShowDashboardNoAccountsNotice([], ['workspace.read']),
        ).toBe(true);
        expect(
            shouldShowDashboardNoAccountsNotice(
                [{ id: 'account-1' }],
                ['workspace.read'],
            ),
        ).toBe(false);
        expect(
            shouldShowDashboardNoAccountsNotice(
                [],
                ['workspace.read', 'workspace.accounts.manage'],
            ),
        ).toBe(false);
    });
});
