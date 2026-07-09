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
        enabled: {
            x: true,
            bluesky: true,
            linkedin: true,
        },
    },
    post_metrics: {
        x: 120,
        bluesky: 240,
        linkedin: 360,
        enabled: {
            x: true,
            bluesky: false,
            linkedin: true,
        },
    },
    account_metrics: {
        x: 720,
        bluesky: 1440,
        linkedin: 2880,
        enabled: {
            x: false,
            bluesky: true,
            linkedin: true,
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
