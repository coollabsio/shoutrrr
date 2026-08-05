import { render, screen } from '@testing-library/react';
import { toast } from 'sonner';
import { beforeAll, describe, expect, it, vi } from 'vitest';

import { Toaster } from '@/components/ui/sonner';

// Toaster -> useFlashToast() -> router.on('flash'). Uninitialized router throws.
vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn(() => vi.fn()) },
    usePage: () => ({ props: {} }),
}));

beforeAll(() => {
    // Toaster -> useAppearance() -> prefersDark() -> window.matchMedia, which
    // jsdom does not implement.
    globalThis.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }) as unknown as typeof window.matchMedia;
});

const INTENTS = [
    { fire: toast.success, message: 'Published', type: 'success' },
    { fire: toast.error, message: 'Publish failed', type: 'error' },
    { fire: toast.warning, message: 'Nearly out of room', type: 'warning' },
    { fire: toast.info, message: 'Draft autosaved', type: 'info' },
    { fire: toast.loading, message: 'Publishing', type: 'loading' },
] as const;

async function toastElement(message: string): Promise<HTMLElement> {
    const el = await screen.findByText(message);
    const li = el.closest('li');

    if (!li) {
        throw new Error(`No toast element found for message "${message}"`);
    }

    return li;
}

describe('Toaster intent styling contract', () => {
    it.each(INTENTS)(
        'gives $type toasts the cn-toast class and a matching data-type',
        async ({ fire, message, type }) => {
            render(<Toaster />);
            fire(message);

            const li = await toastElement(message);

            // The intent CSS in app.css keys off exactly these two attributes.
            expect(li).toHaveClass('cn-toast');
            expect(li).toHaveAttribute('data-type', type);
        },
    );

    it('renders a structurally distinct icon per intent', async () => {
        render(<Toaster />);
        INTENTS.forEach(({ fire, message }) => fire(message));

        const icons: string[] = [];

        for (const { message } of INTENTS) {
            const li = await toastElement(message);
            const svg = li.querySelector('[data-icon] svg');

            expect(svg).not.toBeNull();
            // hugeicons doesn't add a per-icon class (or any library class at
            // all), so identity has to be checked via the actual path markup
            // rather than a class name.
            icons.push(svg!.innerHTML);
        }

        // Redundant non-color encoding (WCAG 1.4.1): color alone must not be
        // the only thing separating success from error.
        expect(new Set(icons).size).toBe(INTENTS.length);
    });
});
