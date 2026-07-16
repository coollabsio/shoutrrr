import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import {
    pollingWithMinutes,
    pollingWithPlatformEnabled,
    type PollingSettings,
} from '../instance-polling';

const settings: PollingSettings = {
    metrics_enabled: true,
    engagement_enabled: true,
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

    it('labels the engagement interval as a minimum floor, not a fixed cadence', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/pages/settings/instance-polling.tsx',
            ),
            'utf8',
        );

        // Engagement polling is adaptive (age bands + steady tail); the operator
        // interval is a floor, so the copy must not imply a fixed cadence.
        expect(source).toContain('minutesHelp="Minimum interval in minutes."');
        expect(source).toMatch(/minimum time between reply checks/i);
    });

    it('labels the post-metrics interval as a minimum floor, not a fixed cadence', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/pages/settings/instance-polling.tsx',
            ),
            'utf8',
        );

        // Post-metrics polling is now age-banded with unchanged-streak backoff;
        // the operator interval is a floor, so the copy must not imply a fixed
        // cadence. Two "PollingCard"s now share this exact minutesHelp string
        // (engagement + post metrics) — assert it appears at least twice.
        expect(
            source.match(/minutesHelp="Minimum interval in minutes\."/g),
        ).toHaveLength(2);
        expect(source).toMatch(/minimum time between metric refreshes/i);
    });

    it('offers instance-wide master switches for metrics and engagement, above the per-platform cards', () => {
        const source = readFileSync(
            resolve(
                process.cwd(),
                'resources/js/pages/settings/instance-polling.tsx',
            ),
            'utf8',
        );

        expect(source).toContain('id="engagement_enabled"');
        expect(source).toContain('id="metrics_enabled"');
        expect(source).toMatch(/setData\(\s*'engagement_enabled'/);
        expect(source).toMatch(/setData\(\s*'metrics_enabled'/);
        // The metrics master switch disables both metrics cards; engagement's disables its own.
        expect(source).toContain('disabled={!data.engagement_enabled}');
        expect(source).toMatch(
            /disabled=\{!data\.metrics_enabled\}[\s\S]*disabled=\{!data\.metrics_enabled\}/,
        );
    });
});
