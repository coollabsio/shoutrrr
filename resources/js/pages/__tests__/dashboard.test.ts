import { describe, expect, it } from 'vitest';

import { shouldShowDashboardPublishingSection } from '../dashboard';

describe('shouldShowDashboardPublishingSection', () => {
    it('hides the composer until at least one account is connected', () => {
        expect(shouldShowDashboardPublishingSection([])).toBe(false);
        expect(
            shouldShowDashboardPublishingSection([{ id: 'account-1' }]),
        ).toBe(true);
    });
});
