import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
    pollingWithMinutes,
    pollingWithPlatformEnabled,
    type PollingSettings,
} from '../instance-polling';

const settings: PollingSettings = {
    engagement: {
        x: 15,
        bluesky: 30,
        linkedin: 60,
        facebook: 15,
        instagram: 15,
        threads: 15,
        discord: 15,
        enabled: {
            x: true,
            bluesky: true,
            linkedin: true,
            facebook: true,
            instagram: true,
            threads: true,
            discord: false,
        },
    },
    post_metrics: {
        x: 120,
        bluesky: 240,
        linkedin: 360,
        facebook: 15,
        instagram: 15,
        threads: 15,
        discord: 15,
        enabled: {
            x: true,
            bluesky: false,
            linkedin: true,
            facebook: true,
            instagram: true,
            threads: true,
            discord: true,
        },
    },
    account_metrics: {
        x: 720,
        bluesky: 1440,
        linkedin: 2880,
        facebook: 15,
        instagram: 15,
        threads: 15,
        discord: 15,
        enabled: {
            x: false,
            bluesky: true,
            linkedin: true,
            facebook: true,
            instagram: true,
            threads: true,
            discord: true,
        },
    },
};

describe('instance polling settings', () => {
    it('updates interval minutes without changing enabled platforms', () => {
        expect(pollingWithMinutes(settings, 'engagement', 'x', '45')).toEqual({
            ...settings,
            engagement: {
                ...settings.engagement,
                x: 45,
            },
        });

        expect(pollingWithMinutes(settings, 'engagement', 'x', '')).toEqual({
            ...settings,
            engagement: {
                ...settings.engagement,
                x: 0,
            },
        });
    });

    it('updates platform enabled state without changing interval minutes', () => {
        expect(
            pollingWithPlatformEnabled(
                settings,
                'post_metrics',
                'bluesky',
                true,
            ),
        ).toEqual({
            ...settings,
            post_metrics: {
                ...settings.post_metrics,
                enabled: {
                    ...settings.post_metrics.enabled,
                    bluesky: true,
                },
            },
        });
    });
});

describe('instance polling section rendering', () => {
    it('renders each section from the backend sections prop, not a hardcoded list', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/pages/settings/instance-polling.tsx',
            ),
            'utf8',
        );

        // No module-level hardcoded platform list.
        expect(source).not.toMatch(/const platforms(\s*):?[^=]*=\s*\[/);
        // PollingCard is driven by a per-section platforms prop sourced from `sections`.
        expect(source).toContain('platforms={sections.');
        expect(source).toContain('sections.engagement');
    });
});
