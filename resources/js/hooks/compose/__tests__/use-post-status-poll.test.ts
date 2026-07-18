/** @vitest-environment jsdom */

import { usePoll } from '@inertiajs/react';
import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { usePostStatusPoll } from '@/hooks/compose/use-post-status-poll';
import type { PostView, TargetStatus, TargetView } from '@/types/compose';

vi.mock('@inertiajs/react', () => ({
    usePoll: vi.fn(),
}));

const start = vi.fn();
const stop = vi.fn();
let root: Root | null = null;
let container: HTMLDivElement | null = null;

function target(id: string, status: TargetStatus): TargetView {
    return {
        id,
        connected_account_id: `account-${id}`,
        platform: 'x',
        handle: '@handle',
        display_name: null,
        avatar_url: null,
        sections: ['Hello'],
        content_override: null,
        auto_split: true,
        format: 'feed',
        issues: [],
        status,
        error_kind: null,
        error_message: null,
        attempts: 0,
        remote_id: null,
    };
}

function post(targets: TargetView[], status: PostView['status']): PostView {
    return {
        id: 'post-1',
        base_text: 'Hello',
        segments: ['Hello'],
        status,
        published_at: null,
        updated_at: '2026-07-16T10:00:00+00:00',
        scheduled_at: null,
        destination: { kind: 'all', id: null },
        targets,
        media: [],
    };
}

function Harness({ value }: { value: PostView }) {
    usePostStatusPoll(value);

    return null;
}

beforeEach(() => {
    vi.mocked(usePoll).mockReturnValue({ start, stop });
    container = document.createElement('div');
    root = createRoot(container);
});

afterEach(() => {
    act(() => root?.unmount());
    root = null;
    container = null;
    vi.clearAllMocks();
});

describe('usePostStatusPoll', () => {
    it('keeps polling after the first target publishes while another is queued', () => {
        act(() => {
            root?.render(
                createElement(Harness, {
                    value: post(
                        [
                            target('published', 'published'),
                            target('queued', 'pending'),
                        ],
                        'publishing',
                    ),
                }),
            );
        });

        expect(usePoll).toHaveBeenCalledWith(
            3000,
            { only: ['post', 'stats'] },
            { autoStart: false },
        );
        expect(start).toHaveBeenCalledOnce();
    });

    it('stops polling once every target is terminal', () => {
        act(() => {
            root?.render(
                createElement(Harness, {
                    value: post([target('first', 'publishing')], 'publishing'),
                }),
            );
        });

        act(() => {
            root?.render(
                createElement(Harness, {
                    value: post([target('first', 'published')], 'published'),
                }),
            );
        });

        expect(stop).toHaveBeenCalledOnce();
    });
});
