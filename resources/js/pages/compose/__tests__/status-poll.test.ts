/** @vitest-environment jsdom */

import { act, createElement, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { usePostStatusPoll } from '@/hooks/compose/use-post-status-poll';
import ComposePage from '@/pages/compose/index';
import type { PostView, TargetStatus, TargetView } from '@/types/compose';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: { children?: ReactNode }) => children,
    usePage: () => ({ props: { features: { analytics: true } } }),
}));

vi.mock('@/components/compose/composer', () => ({
    default: () => 'composer',
}));

vi.mock('@/components/posts/post-page-actions', () => ({
    PostPageActions: () => null,
}));

vi.mock('@/components/posts/published-post-view', () => ({
    PublishedPostView: () => 'published-view',
}));

vi.mock('@/components/ui/button', () => ({
    Button: ({ children }: { children?: ReactNode }) => children,
}));

vi.mock('@/hooks/compose/use-post-status-poll', () => ({
    usePostStatusPoll: vi.fn(),
}));

vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/dashboard' }),
}));

vi.mock('@/routes/posts', () => ({
    index: () => ({ url: '/posts' }),
}));

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

function publishingPost(): PostView {
    return {
        id: 'post-1',
        base_text: 'Hello',
        segments: ['Hello'],
        status: 'publishing',
        published_at: '2026-07-16T10:00:00+00:00',
        updated_at: '2026-07-16T10:00:00+00:00',
        scheduled_at: null,
        destination: { kind: 'all', id: null },
        targets: [
            target('published', 'published'),
            target('queued', 'pending'),
        ],
        media: [],
    };
}

beforeEach(() => {
    container = document.createElement('div');
    root = createRoot(container);
});

afterEach(() => {
    act(() => root?.unmount());
    root = null;
    container = null;
    vi.clearAllMocks();
});

describe('compose status polling', () => {
    it('keeps the page-level poll mounted when the published view replaces the composer', () => {
        const post = publishingPost();

        act(() => {
            root?.render(
                createElement(ComposePage, {
                    post,
                    accounts: [],
                    sets: [],
                    limits: [],
                    savedMentions: [],
                }),
            );
        });

        expect(container?.textContent).toContain('published-view');
        expect(container?.textContent).not.toContain('composer');
        expect(usePostStatusPoll).toHaveBeenCalledWith(post);
    });
});
