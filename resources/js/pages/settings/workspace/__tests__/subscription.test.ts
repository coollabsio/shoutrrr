import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { remainingXPostLabel } from '../subscription';

const source = () =>
    readFileSync(
        resolve(
            process.cwd(),
            'resources/js/pages/settings/workspace/subscription.tsx',
        ),
        'utf8',
    );

describe('subscription checkout forms', () => {
    it('lives in the workspace settings navigation', () => {
        const app = readFileSync(
            resolve(process.cwd(), 'resources/js/app.tsx'),
            'utf8',
        );
        const workspaceLayout = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/layouts/settings/workspace-layout.tsx',
            ),
            'utf8',
        );
        const accountSettingsLayout = readFileSync(
            resolve(process.cwd(), 'resources/js/layouts/settings/layout.tsx'),
            'utf8',
        );

        expect(app).toContain("name.startsWith('settings/workspace')");
        expect(workspaceLayout).toContain("title: 'Subscription'");
        expect(workspaceLayout).toContain('BillingController.index()');
        expect(accountSettingsLayout).not.toContain("title: 'Subscription'");
    });

    it('uses native forms for Stripe redirects instead of Inertia XHR forms', () => {
        const subscriptionPage = source();

        expect(subscriptionPage).not.toContain('Form, Head');
        expect(subscriptionPage).not.toContain('<Form');
        expect(subscriptionPage).toContain('<form');
        expect(subscriptionPage).toContain('BillingController.checkout.url()');
        expect(subscriptionPage).toContain('BillingController.portal.url()');
        expect(subscriptionPage).toContain('name="_token"');
    });

    it('renders current month X post usage and unlimited non-X publishing copy', () => {
        const subscriptionPage = source();

        expect(subscriptionPage).toContain('X posts this month');
        expect(subscriptionPage).toContain('monthlyXPostUsed');
        expect(subscriptionPage).toContain('monthlyXPostRemaining');
        expect(subscriptionPage).toContain('unlimited publishes to');
        expect(subscriptionPage).toContain('every other platform');
        expect(subscriptionPage).toContain('X/Twitter');
        expect(subscriptionPage).toContain('publish requests each month');
    });

    it('labels remaining X posts as unlimited when the plan has no monthly limit', () => {
        expect(remainingXPostLabel(null, 10)).toBe('Unlimited remaining');
        expect(remainingXPostLabel(null, null)).toBe('Unlimited remaining');
        expect(remainingXPostLabel(100, null)).toBe('Unlimited remaining');
        expect(remainingXPostLabel(100, 25)).toBe('25 remaining');
    });
});
