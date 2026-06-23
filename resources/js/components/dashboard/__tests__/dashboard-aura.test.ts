import { describe, expect, it } from 'vitest';

import { dashboardAuraBackdropClassName } from '../dashboard-aura';

describe('dashboardAuraBackdropClassName', () => {
    it('extends the aura behind the dashboard navbar', () => {
        expect(dashboardAuraBackdropClassName).toContain('-top-16');
        expect(dashboardAuraBackdropClassName).toContain('h-[624px]');
    });

    it('keeps the aura behind dashboard content', () => {
        expect(dashboardAuraBackdropClassName).toContain('-z-10');
    });
});
