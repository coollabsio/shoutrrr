import { describe, expect, it } from 'vitest';

import {
    canFetchXUsage,
    formatMoney,
    usageQuery,
    xUsageTotal,
} from '../instance-usage';

describe('instance usage filters', () => {
    it('omits default/cleared filters from the query', () => {
        expect(
            usageQuery({ search: null, sort: 'spend', workspace: null }),
        ).toEqual({});
        expect(
            usageQuery({
                search: 'acme',
                sort: 'spend',
                workspace: null,
            }),
        ).toEqual({ search: 'acme' });
        expect(
            usageQuery({ search: null, sort: 'name', workspace: null }),
        ).toEqual({ sort: 'name' });
        expect(
            usageQuery({
                search: null,
                sort: 'spend',
                workspace: 'workspace-1',
            }),
        ).toEqual({ workspace: 'workspace-1' });
    });
});

describe('instance usage money formatting', () => {
    it('uses the configured currency code', () => {
        expect(formatMoney(1.23, 'EUR')).toContain('€');
        expect(formatMoney(1.23, 'USD')).toContain('$');
    });
});

describe('x usage response helpers', () => {
    it('disables fetching when the bearer token is missing or a request is running', () => {
        expect(canFetchXUsage(false, false)).toBe(false);
        expect(canFetchXUsage(true, true)).toBe(false);
        expect(canFetchXUsage(true, false)).toBe(true);
    });

    it('uses project usage when available', () => {
        expect(xUsageTotal({ project_usage: 15420 })).toBe(15420);
    });

    it('falls back to summing daily project app usage', () => {
        expect(
            xUsageTotal({
                daily_project_usage: [
                    {
                        date: '2026-07-01',
                        usage: [
                            { app_id: '1', tweets_consumed: 10 },
                            { app_id: '2', tweets_consumed: 5 },
                        ],
                    },
                ],
            }),
        ).toBe(15);
    });
});
