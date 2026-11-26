import { describe, expect, it } from 'vitest';

import {
    DEFAULT_TIKTOK_OPTIONS,
    disclosureLabel,
    hasIncompleteDisclosure,
    isSelfOnly,
    tikTokBlockReasons,
    type TikTokPrecheck,
} from '@/lib/compose/tiktok';
import type {
    TikTokCreatorInfo,
    TikTokCreatorState,
    TikTokOptions,
} from '@/types/compose';

function creatorInfo(over: Partial<TikTokCreatorInfo> = {}): TikTokCreatorInfo {
    return {
        nickname: 'Harbor Studio',
        username: 'harbor.studio',
        privacy_levels: [
            { value: 'PUBLIC_TO_EVERYONE', label: 'Everyone' },
            { value: 'SELF_ONLY', label: 'Only me' },
        ],
        comment_disabled: false,
        duet_disabled: false,
        stitch_disabled: false,
        max_video_duration_seconds: 600,
        ...over,
    };
}

function available(over: Partial<TikTokCreatorInfo> = {}): TikTokCreatorState {
    return { status: 'available', creator: creatorInfo(over) };
}

function check(
    options: Partial<TikTokOptions>,
    rest: Partial<Omit<TikTokPrecheck, 'options'>> = {},
) {
    return tikTokBlockReasons({
        options: { ...DEFAULT_TIKTOK_OPTIONS, ...options },
        creator: available(),
        videoDurationSeconds: 30,
        isPhotoPost: false,
        ...rest,
    });
}

describe('DEFAULT_TIKTOK_OPTIONS', () => {
    it('starts with no privacy level and every interaction and disclosure off', () => {
        expect(DEFAULT_TIKTOK_OPTIONS).toEqual({
            post_mode: 'direct',
            privacy_level: null,
            allow_comment: false,
            allow_duet: false,
            allow_stitch: false,
            disclose_commercial: false,
            brand_organic: false,
            branded_content: false,
        });
    });
});

describe('tikTokBlockReasons', () => {
    it('requires a privacy level for a Direct Post', () => {
        expect(check({})).toEqual(['tiktok_privacy_required']);
        expect(check({ privacy_level: 'PUBLIC_TO_EVERYONE' })).toEqual([]);
    });

    it('never blocks an inbox draft, which the creator finishes in TikTok', () => {
        expect(
            check(
                { post_mode: 'inbox', disclose_commercial: true },
                { creator: { status: 'unavailable', message: 'nope' } },
            ),
        ).toEqual([]);
    });

    it('blocks while TikTok refuses posts from the creator', () => {
        expect(
            check(
                { privacy_level: 'PUBLIC_TO_EVERYONE' },
                { creator: { status: 'unavailable', message: 'Busy' } },
            ),
        ).toEqual(['tiktok_creator_unavailable']);
    });

    it('rejects a privacy level the creator no longer offers', () => {
        expect(check({ privacy_level: 'MUTUAL_FOLLOW_FRIENDS' })).toEqual([
            'tiktok_privacy_unavailable',
        ]);
    });

    it('rejects an interaction the creator has turned off', () => {
        const creator = available({ comment_disabled: true });

        expect(
            check(
                { privacy_level: 'SELF_ONLY', allow_comment: true },
                { creator },
            ),
        ).toEqual(['tiktok_interaction_disabled']);
        expect(check({ privacy_level: 'SELF_ONLY' }, { creator })).toEqual([]);
    });

    it('ignores duet and stitch on a photo post', () => {
        expect(
            check(
                {
                    privacy_level: 'SELF_ONLY',
                    allow_duet: true,
                    allow_stitch: true,
                },
                {
                    creator: available({
                        duet_disabled: true,
                        stitch_disabled: true,
                    }),
                    isPhotoPost: true,
                    videoDurationSeconds: null,
                },
            ),
        ).toEqual([]);
    });

    it('blocks a video longer than the creator may post', () => {
        const creator = available({ max_video_duration_seconds: 60 });

        expect(
            check(
                { privacy_level: 'SELF_ONLY' },
                { creator, videoDurationSeconds: 61 },
            ),
        ).toEqual(['tiktok_video_too_long']);
        expect(
            check(
                { privacy_level: 'SELF_ONLY' },
                { creator, videoDurationSeconds: 60 },
            ),
        ).toEqual([]);
    });

    it('does not treat a zero maximum duration as a limit', () => {
        expect(
            check(
                { privacy_level: 'SELF_ONLY' },
                {
                    creator: available({ max_video_duration_seconds: 0 }),
                    videoDurationSeconds: 300,
                },
            ),
        ).toEqual([]);
    });

    it('requires a disclosure choice once disclosure is switched on', () => {
        expect(
            check({
                privacy_level: 'PUBLIC_TO_EVERYONE',
                disclose_commercial: true,
            }),
        ).toEqual(['tiktok_disclosure_incomplete']);
        expect(
            check({
                privacy_level: 'PUBLIC_TO_EVERYONE',
                disclose_commercial: true,
                brand_organic: true,
            }),
        ).toEqual([]);
    });

    it('checks only the options while creator info is still loading', () => {
        expect(check({}, { creator: { status: 'loading' } })).toEqual([
            'tiktok_privacy_required',
        ]);
        expect(
            check(
                { privacy_level: 'MUTUAL_FOLLOW_FRIENDS' },
                { creator: undefined },
            ),
        ).toEqual([]);
    });
});

describe('disclosureLabel', () => {
    it('labels branded content as a paid partnership', () => {
        expect(
            disclosureLabel({
                ...DEFAULT_TIKTOK_OPTIONS,
                disclose_commercial: true,
                branded_content: true,
                brand_organic: true,
            }),
        ).toBe('Paid partnership');
    });

    it('labels your own brand alone as promotional content', () => {
        expect(
            disclosureLabel({
                ...DEFAULT_TIKTOK_OPTIONS,
                disclose_commercial: true,
                brand_organic: true,
            }),
        ).toBe('Promotional content');
    });

    it('has no label without a complete disclosure', () => {
        expect(disclosureLabel(DEFAULT_TIKTOK_OPTIONS)).toBeNull();
        expect(
            disclosureLabel({
                ...DEFAULT_TIKTOK_OPTIONS,
                disclose_commercial: true,
            }),
        ).toBeNull();
    });
});

describe('hasIncompleteDisclosure', () => {
    it('is true only when disclosure is on with nothing ticked', () => {
        expect(hasIncompleteDisclosure(DEFAULT_TIKTOK_OPTIONS)).toBe(false);
        expect(
            hasIncompleteDisclosure({
                ...DEFAULT_TIKTOK_OPTIONS,
                disclose_commercial: true,
            }),
        ).toBe(true);
        expect(
            hasIncompleteDisclosure({
                ...DEFAULT_TIKTOK_OPTIONS,
                disclose_commercial: true,
                branded_content: true,
            }),
        ).toBe(false);
    });
});

it('recognizes the private privacy level', () => {
    expect(isSelfOnly('SELF_ONLY')).toBe(true);
    expect(isSelfOnly('PUBLIC_TO_EVERYONE')).toBe(false);
    expect(isSelfOnly(null)).toBe(false);
});
