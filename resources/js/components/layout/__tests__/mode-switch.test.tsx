import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ModeSwitch } from '@/components/layout/mode-switch';
import { SidebarProvider } from '@/components/ui/sidebar';

const { router } = vi.hoisted(() => {
    // use-mobile reads matchMedia at import time.
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    });

    return { router: { put: vi.fn(), visit: vi.fn() } };
});

vi.mock('@inertiajs/react', () => ({
    router,
    usePage: () => ({ props: { auth: { user: { ui_mode: 'agentic' } } } }),
}));

function renderModeSwitch() {
    render(
        <SidebarProvider>
            <ModeSwitch />
        </SidebarProvider>,
    );
}

describe('mode switch', () => {
    beforeEach(() => {
        router.put.mockReset();
        router.visit.mockReset();
    });

    it('exposes each mode as a toggle button showing which one is active', () => {
        renderModeSwitch();

        expect(screen.queryByRole('radio')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Agentic' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(screen.getByRole('button', { name: 'Classic' })).toHaveAttribute(
            'aria-pressed',
            'false',
        );
    });

    it('does nothing when the active mode is clicked', () => {
        renderModeSwitch();

        fireEvent.click(screen.getByRole('button', { name: 'Agentic' }));

        expect(router.put).not.toHaveBeenCalled();
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('switches to the other mode', () => {
        renderModeSwitch();

        fireEvent.click(screen.getByRole('button', { name: 'Classic' }));

        expect(router.put).toHaveBeenCalledWith(
            expect.stringContaining('ui-mode'),
            { mode: 'classic' },
            { preserveScroll: true },
        );
    });
});
