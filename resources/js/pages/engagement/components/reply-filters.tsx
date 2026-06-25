import { router } from '@inertiajs/react';

import { index as engagementRoute } from '@/routes/engagement';

import type { AccountFacet, EngagementFilters } from '../types';

type Props = {
    filters: EngagementFilters;
    accounts: AccountFacet[];
};

export function ReplyFilters({ filters, accounts }: Props) {
    function update(patch: Partial<EngagementFilters>) {
        const next = { ...filters, ...patch };
        router.get(
            engagementRoute().url,
            next as Record<string, string | boolean>,
            {
                preserveState: true,
                preserveScroll: true,
                only: ['replies', 'filters'],
                replace: true,
            },
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-2 border-b p-3">
            <button
                type="button"
                onClick={() => update({ unread: !filters.unread })}
                className={`rounded-md px-2.5 py-1 text-sm ${filters.unread ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'}`}
            >
                Unread
            </button>

            <select
                value={filters.platform}
                onChange={(e) => update({ platform: e.target.value })}
                className="rounded-md border bg-background px-2 py-1 text-sm"
            >
                <option value="">All platforms</option>
                <option value="bluesky">Bluesky</option>
                <option value="x">X</option>
                <option value="linkedin">LinkedIn</option>
            </select>

            <select
                value={filters.account}
                onChange={(e) => update({ account: e.target.value })}
                className="rounded-md border bg-background px-2 py-1 text-sm"
            >
                <option value="">All accounts</option>
                {accounts.map((a) => (
                    <option key={a.id} value={a.id}>
                        {a.handle ?? a.id} ({a.platform})
                    </option>
                ))}
            </select>
        </div>
    );
}
