import { describe, expect, it } from 'vitest';

import { usageQuery } from '../instance-usage';

describe('instance usage filters', () => {
    it('omits cleared platform filters from the query', () => {
        expect(usageQuery(null, null)).toEqual({});
        expect(usageQuery('workspace-1', null)).toEqual({
            workspace: 'workspace-1',
        });
        expect(usageQuery(null, 'x')).toEqual({ platform: 'x' });
    });
});
