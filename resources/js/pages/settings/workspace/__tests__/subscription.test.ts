import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { remainingXBudgetLabel } from '../subscription';

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

    it('adds workspace subscription breadcrumbs to the app header', () => {
        const subscriptionPage = source();

        expect(subscriptionPage).toContain('Subscription.layout');
        expect(subscriptionPage).toContain("title: 'Workspace settings'");
        expect(subscriptionPage).toContain(
            'WorkspaceSettingsController.showOverview().url',
        );
        expect(subscriptionPage).toContain("title: 'Subscription'");
        expect(subscriptionPage).toContain('BillingController.index().url');
    });

    it('renders current month X budget usage and unlimited non-X publishing copy', () => {
        const subscriptionPage = source();

        expect(subscriptionPage).toContain('X budget this month');
        expect(subscriptionPage).toContain('monthlyXBudgetUsedMicrousd');
        expect(subscriptionPage).toContain('monthlyXBudgetRemainingMicrousd');
        expect(subscriptionPage).toContain('unlimited publishes to');
        expect(subscriptionPage).toContain('every other platform');
        expect(subscriptionPage).toContain('X/Twitter');
        expect(subscriptionPage).toContain('usage budget');
    });

    it('labels remaining X budget as dollars', () => {
        expect(remainingXBudgetLabel(null)).toBe('Unlimited remaining');
        expect(remainingXBudgetLabel(4_820_000)).toBe('$4.82 remaining');
        expect(remainingXBudgetLabel(15_000)).toBe('$0.015 remaining');
    });
});
