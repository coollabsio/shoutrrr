import { describe, expect, it } from 'vitest';

import { engagementItems } from '../engagement-metrics';

const stat = { likes: 820, comments: 210, reposts: 54, impressions: 12_400 };

describe('engagementItems', () => {
    it('orders X as replies, reposts, likes, views', () => {
        expect(engagementItems('x', stat)).toEqual([
            { key: 'comments', label: 'replies', value: 210 },
            { key: 'reposts', label: 'reposts', value: 54 },
            { key: 'likes', label: 'likes', value: 820 },
            { key: 'views', label: 'views', value: 12_400 },
        ]);
    });

    it('omits views on Bluesky', () => {
        const items = engagementItems('bluesky', stat);

        expect(items.map((i) => i.key)).toEqual([
            'comments',
            'reposts',
            'likes',
        ]);
    });

    it('drops the views slot when impressions were not captured', () => {
        const items = engagementItems('x', { ...stat, impressions: null });

        expect(items.map((i) => i.key)).toEqual([
            'comments',
            'reposts',
            'likes',
        ]);
    });

    it('labels LinkedIn impressions and leads with likes', () => {
        const items = engagementItems('linkedin', stat);

        expect(items.map((i) => i.label)).toEqual([
            'likes',
            'comments',
            'reposts',
            'impressions',
        ]);
    });
});
