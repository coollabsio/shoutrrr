/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { beforeAll, describe, expect, it, vi } from 'vitest';

import { Button, buttonVariants } from '@/components/ui/button';

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
});

function renderInForm(buttonProps: Record<string, unknown>) {
    const onSubmit = vi.fn((e: Event) => e.preventDefault());
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);

    act(() => {
        root.render(
            createElement(
                'form',
                { onSubmit },
                createElement(Button, buttonProps, 'Save'),
            ),
        );
    });

    const button = container.querySelector('button') as HTMLButtonElement;
    act(() => button.click());

    return {
        onSubmit,
        buttonType: button.getAttribute('type'),
        cleanup: () => {
            act(() => root.unmount());
            container.remove();
        },
    };
}

// Base UI's Button renders type="button" by default (Radix's shadcn Button left
// it unset, so a native <button> defaulted to type="submit" inside a form). The
// migration silently broke every form-submit <Button> that relied on that
// default (forgot-password, confirm-password, security, profile). tsc/build
// cannot catch it — `type` is a valid, optional attribute either way.
describe('button', () => {
    it('does not submit its form by default (Base UI type=button)', () => {
        const { onSubmit, buttonType, cleanup } = renderInForm({});
        expect(buttonType).toBe('button');
        expect(onSubmit).not.toHaveBeenCalled();
        cleanup();
    });

    it('submits its form when given type="submit"', () => {
        const { onSubmit, buttonType, cleanup } = renderInForm({
            type: 'submit',
        });
        expect(buttonType).toBe('submit');
        expect(onSubmit).toHaveBeenCalledTimes(1);
        cleanup();
    });
});

// The emphasis gradient is purely visual, so tsc and the build cannot catch its
// removal, and jsdom cannot resolve the color-mix() behind it. Pin the class
// contract instead: the four --primary-gradient-* tokens are defined in
// app.css:root, and dropping either side silently flattens every primary CTA.
describe('button gradient', () => {
    it('applies the gradient wash, top highlight and edge to the default variant', () => {
        const classes = buttonVariants({ variant: 'default' });

        expect(classes).toContain('bg-primary-gradient');
        expect(classes).toContain('border-(--primary-gradient-edge)');
        expect(classes).toContain(
            'inset-shadow-[0_1px_0_0_var(--primary-gradient-highlight)]',
        );
    });

    // The wash must use inset-shadow-*, not shadow-[inset_...]. Both render the
    // same alone, but shadow-* sets --tw-shadow, which call sites like the
    // onboarding badge already claim with shadow-md — one would silently drop
    // the other. inset-shadow-* writes --tw-inset-shadow and composes instead.
    it('uses the composable inset-shadow utility for the top highlight', () => {
        expect(buttonVariants({ variant: 'default' })).not.toContain(
            'shadow-[inset_',
        );
    });

    // Hover moves only the top stop, via the indirection hook the utility reads.
    it('lifts the top stop on hover without restating the gradient', () => {
        expect(buttonVariants({ variant: 'default' })).toContain(
            'hover:[--primary-gradient-top:var(--primary-gradient-highlight)]',
        );
    });

    // `transition-property: all` does NOT cover custom properties — verified in
    // Chromium: with `all` the stop jumps straight to its hover value, while
    // naming it lands exactly mid-interpolation. So plain `transition-all` here
    // would make hover snap instead of fade, with nothing else failing.
    it('names the custom property in the transition so hover can tween', () => {
        const classes = buttonVariants({ variant: 'default' });

        expect(classes).toMatch(
            /transition-\[[^\]]*--primary-gradient-top[^\]]*\]/,
        );
        expect(classes).not.toMatch(/\btransition-all\b/);
    });

    it('keeps the low-emphasis variants flat', () => {
        for (const variant of ['outline', 'secondary', 'ghost', 'link'] as const) {
            expect(buttonVariants({ variant })).not.toContain(
                'bg-primary-gradient',
            );
        }
    });
});
