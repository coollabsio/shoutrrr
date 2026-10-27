import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { PipelineConnectionWarning } from './pipeline-connection-warning';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
    }: {
        children: ReactNode;
        href: { url: string };
    }) => <a href={href.url}>{children}</a>,
}));

describe('pipeline connection warning', () => {
    it('identifies disconnected accounts in an enabled pipeline', () => {
        render(
            <PipelineConnectionWarning
                enabled
                accounts={[
                    { handle: '@heyandras', status: 'active', enabled: true },
                    {
                        handle: '@heyandras.dev',
                        status: 'needs_attention',
                        enabled: true,
                    },
                ]}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('@heyandras.dev');
        expect(screen.getByRole('alert')).not.toHaveTextContent('@heyandras,');
        expect(screen.getByRole('link')).toHaveAttribute('href', '/accounts');
        expect(screen.getByRole('link')).toHaveTextContent(
            'Check connected accounts',
        );
    });

    it('names disabled accounts in an enabled pipeline', () => {
        render(
            <PipelineConnectionWarning
                enabled
                accounts={[
                    { handle: '@acme', status: 'active', enabled: false },
                    { handle: '@ok', status: 'active', enabled: true },
                ]}
            />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Sync is blocked for @acme. Enable or reconnect',
        );
    });

    it('warns when a pipeline references an unavailable account', () => {
        render(<PipelineConnectionWarning enabled accounts={[undefined]} />);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'an unavailable account',
        );
    });

    it.each([true, false])(
        'hides warnings for healthy pipelines when enabled is %s',
        (enabled) => {
            render(
                <PipelineConnectionWarning
                    enabled={enabled}
                    accounts={[
                        { handle: '@healthy', status: 'active', enabled: true },
                    ]}
                />,
            );
            expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        },
    );

    it('does not show a blocked warning for a disabled pipeline', () => {
        render(
            <PipelineConnectionWarning
                enabled={false}
                accounts={[
                    {
                        handle: '@offline',
                        status: 'needs_attention',
                        enabled: true,
                    },
                ]}
            />,
        );
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});
