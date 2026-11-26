import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import CreateSyncPipelineDialog, {
    type SyncAccount,
} from '../create-sync-pipeline-dialog';

vi.mock('sonner', () => ({ toast: { success: vi.fn() } }));

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
    }: {
        children: (form: {
            errors: Record<string, string>;
            processing: boolean;
        }) => ReactNode;
    }) => <form>{children({ errors: {}, processing: false })}</form>,
}));

function account(id: string, platform: string, handle: string): SyncAccount {
    return {
        id,
        platform,
        handle,
        display_name: null,
        avatar_url: null,
        status: 'active',
        supports_native: false,
    };
}

const INBOX_NOTE = /TikTok gets each synced post as an inbox draft/;

function openWithSource(): void {
    render(
        <CreateSyncPipelineDialog
            accounts={[
                account('x-1', 'x', '@source'),
                account('tiktok-1', 'tiktok', '@clips'),
                account('linkedin-1', 'linkedin', 'Company'),
            ]}
            disabled={false}
            trackedAccountIds={[]}
            canTrack={false}
            maxTracked={3}
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'New pipeline' }));
    fireEvent.click(screen.getAllByRole('radio')[0]);
}

describe('CreateSyncPipelineDialog', () => {
    it('says a TikTok destination gets synced posts as inbox drafts', () => {
        openWithSource();
        expect(screen.queryByText(INBOX_NOTE)).toBeNull();

        fireEvent.click(screen.getAllByRole('checkbox')[0]);

        expect(screen.getByText(INBOX_NOTE)).toBeTruthy();
    });

    it('says nothing about TikTok for other destinations', () => {
        openWithSource();

        fireEvent.click(screen.getAllByRole('checkbox')[1]);

        expect(screen.queryByText(INBOX_NOTE)).toBeNull();
    });
});
