/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { beforeAll, describe, expect, it, vi } from 'vitest';

import { Button } from '@/components/ui/button';

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
