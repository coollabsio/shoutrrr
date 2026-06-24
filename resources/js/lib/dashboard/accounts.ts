export function shouldShowDashboardPublishingSection(
    accounts: unknown[],
): boolean {
    return accounts.length > 0;
}

export function canManageConnectedAccounts(permissions: string[]): boolean {
    return permissions.includes('workspace.accounts.manage');
}

export function shouldShowDashboardNoAccountsNotice(
    accounts: unknown[],
    permissions: string[],
): boolean {
    return (
        accounts.length === 0 &&
        permissions.includes('workspace.read') &&
        !canManageConnectedAccounts(permissions)
    );
}
