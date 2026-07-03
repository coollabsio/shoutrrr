import { describe, expect, it } from 'vitest';

import { formatMoney, usageQuery } from '../instance-usage';

describe('instance usage filters', () => {
    it('omits cleared platform filters from the query', () => {
        expect(usageQuery(null, null)).toEqual({});
        expect(usageQuery('workspace-1', null)).toEqual({
            workspace: 'workspace-1',
        });
        expect(usageQuery(null, 'x')).toEqual({ platform: 'x' });
    });
});

describe('instance usage money formatting', () => {
    it('uses the configured currency code', () => {
        expect(formatMoney(1.23, 'EUR')).toContain('€');
        expect(formatMoney(1.23, 'USD')).toContain('$');
    });
});
