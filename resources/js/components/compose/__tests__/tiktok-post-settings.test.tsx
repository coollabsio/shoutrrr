import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { DEFAULT_TIKTOK_OPTIONS } from '@/lib/compose/tiktok';
import type {
    TikTokCreatorInfo,
    TikTokCreatorState,
    TikTokOptions,
} from '@/types/compose';

import {
    TikTokConsentNotice,
    TikTokPostSettings,
} from '../tiktok-post-settings';

const CREATOR: TikTokCreatorInfo = {
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
};

function renderSettings({
    options = DEFAULT_TIKTOK_OPTIONS,
    creator = { status: 'available', creator: CREATOR },
    isPhotoPost = false,
    onChange = vi.fn(),
    onRetryCreator = vi.fn(),
}: {
    options?: TikTokOptions;
    creator?: TikTokCreatorState;
    isPhotoPost?: boolean;
    onChange?: (options: TikTokOptions) => void;
    onRetryCreator?: () => void;
} = {}) {
    return render(
        <TikTokPostSettings
            options={options}
            onChange={onChange}
            creator={creator}
            onRetryCreator={onRetryCreator}
            isPhotoPost={isPhotoPost}
        />,
    );
}

function control(container: HTMLElement, id: string): HTMLElement {
    const element = container.querySelector<HTMLElement>(`#${id}`);
    if (element === null) {
        throw new Error(`#${id} not rendered`);
    }

    return element;
}

function isChecked(element: HTMLElement): boolean {
    return element.getAttribute('aria-checked') === 'true';
}

function isDisabled(element: HTMLElement): boolean {
    return (
        element.hasAttribute('data-disabled') ||
        element.getAttribute('aria-disabled') === 'true' ||
        (element as HTMLButtonElement).disabled === true
    );
}

describe('TikTokPostSettings', () => {
    it('names the creator the post goes to', () => {
        renderSettings();

        expect(screen.getByText(/Posting as/).closest('p')?.textContent).toBe(
            'Posting as Harbor Studio @harbor.studio',
        );
    });

    it('leaves the Direct Post or inbox choice to the toolbar', () => {
        const { container } = renderSettings();

        expect(container.querySelector('[role="radiogroup"]')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Inbox draft' }),
        ).not.toBeInTheDocument();
    });

    it('says when an interaction the creator turned off is still ticked', () => {
        renderSettings({
            options: { ...DEFAULT_TIKTOK_OPTIONS, allow_duet: true },
            creator: {
                status: 'available',
                creator: { ...CREATOR, duet_disabled: true },
            },
        });

        expect(
            screen.getByText(
                'Duet is turned off for this TikTok account — untick it.',
            ),
        ).toBeInTheDocument();
    });

    it('starts with no privacy level and every interaction unticked', () => {
        const { container } = renderSettings();

        expect(screen.getByText('Choose who can view')).toBeInTheDocument();
        for (const id of [
            'tiktok-allow-comment',
            'tiktok-allow-duet',
            'tiktok-allow-stitch',
        ]) {
            expect(isChecked(control(container, id))).toBe(false);
            expect(isDisabled(control(container, id))).toBe(false);
        }
        expect(isChecked(control(container, 'tiktok-disclose'))).toBe(false);
    });

    it('greys out interactions until the creator info loads', () => {
        const { container } = renderSettings({
            creator: { status: 'loading' },
        });

        expect(screen.getByText(/Checking the TikTok account/)).toBeTruthy();
        expect(isDisabled(control(container, 'tiktok-allow-comment'))).toBe(
            true,
        );
        expect(isDisabled(control(container, 'tiktok-privacy'))).toBe(true);
    });

    it('greys out an interaction the creator has turned off', () => {
        const { container } = renderSettings({
            creator: {
                status: 'available',
                creator: { ...CREATOR, duet_disabled: true },
            },
        });

        expect(isDisabled(control(container, 'tiktok-allow-duet'))).toBe(true);
        expect(isDisabled(control(container, 'tiktok-allow-stitch'))).toBe(
            false,
        );
    });

    it('offers only comments on a photo post', () => {
        const { container } = renderSettings({ isPhotoPost: true });

        expect(container.querySelector('#tiktok-allow-comment')).not.toBeNull();
        expect(container.querySelector('#tiktok-allow-duet')).toBeNull();
        expect(container.querySelector('#tiktok-allow-stitch')).toBeNull();
    });

    it('explains when the creator info is unavailable and retries', () => {
        const onRetryCreator = vi.fn();
        renderSettings({
            creator: {
                status: 'unavailable',
                message: 'TikTok is busy right now.',
            },
            onRetryCreator,
        });

        expect(screen.getByText('TikTok is busy right now.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(onRetryCreator).toHaveBeenCalledOnce();
    });

    it('asks which kind of promotion once disclosure is on', () => {
        renderSettings({
            options: { ...DEFAULT_TIKTOK_OPTIONS, disclose_commercial: true },
        });

        expect(
            screen.getByText(
                'You need to indicate if your content promotes yourself, a third party, or both.',
            ),
        ).toBeTruthy();
    });

    it('shows the label TikTok will add for the chosen disclosure', () => {
        renderSettings({
            options: {
                ...DEFAULT_TIKTOK_OPTIONS,
                disclose_commercial: true,
                brand_organic: true,
            },
        });

        expect(screen.getByText(/labeled as/).textContent).toContain(
            'Promotional content',
        );
    });

    it('clears both promotion choices when disclosure is switched off', () => {
        const onChange = vi.fn();
        const { container } = renderSettings({
            options: {
                ...DEFAULT_TIKTOK_OPTIONS,
                disclose_commercial: true,
                brand_organic: true,
                branded_content: true,
            },
            onChange,
        });

        fireEvent.click(control(container, 'tiktok-disclose'));

        expect(onChange).toHaveBeenCalledWith({
            ...DEFAULT_TIKTOK_OPTIONS,
            disclose_commercial: false,
            brand_organic: false,
            branded_content: false,
        });
    });

    it('blocks branded content on a private post', () => {
        const { container } = renderSettings({
            options: {
                ...DEFAULT_TIKTOK_OPTIONS,
                privacy_level: 'SELF_ONLY',
                disclose_commercial: true,
            },
        });

        expect(isDisabled(control(container, 'tiktok-branded-content'))).toBe(
            true,
        );
        expect(
            screen.getByText(
                'Branded content visibility cannot be set to private.',
            ),
        ).toBeTruthy();
    });

    it('drops the Direct Post choices for an inbox draft', () => {
        const { container } = renderSettings({
            options: { ...DEFAULT_TIKTOK_OPTIONS, post_mode: 'inbox' },
        });

        expect(container.querySelector('#tiktok-privacy')).toBeNull();
        expect(container.querySelector('#tiktok-disclose')).toBeNull();
        expect(
            screen.getByText(/TikTok's inbox doesn't take a caption/),
        ).toBeTruthy();
    });
});

describe('TikTokConsentNotice', () => {
    it('links the Music Usage Confirmation', () => {
        render(<TikTokConsentNotice branded={false} />);

        const links = screen.getAllByRole('link');
        expect(links.map((link) => link.textContent)).toEqual([
            'Music Usage Confirmation',
        ]);
    });

    it('adds the Branded Content Policy for branded content', () => {
        render(<TikTokConsentNotice branded />);

        expect(
            screen.getAllByRole('link').map((link) => link.textContent),
        ).toEqual(['Branded Content Policy', 'Music Usage Confirmation']);
    });
});
