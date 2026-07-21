/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { beforeAll, describe, expect, it } from 'vitest';

import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
});

const ITEMS = [
    { value: 'never', label: 'Never' },
    { value: '1d', label: 'In 24 hours' },
];

function renderSelect(props: Record<string, unknown>) {
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);

    act(() => {
        root.render(
            createElement(
                Select,
                { value: '1d', ...props },
                createElement(
                    SelectTrigger,
                    null,
                    createElement(SelectValue, null),
                ),
                createElement(
                    SelectContent,
                    null,
                    ...ITEMS.map((item) =>
                        createElement(
                            SelectItem,
                            { key: item.value, value: item.value },
                            item.label,
                        ),
                    ),
                ),
            ),
        );
    });

    const trigger = document.querySelector(
        '[data-slot="select-value"]',
    ) as HTMLElement | null;

    return {
        triggerText: trigger?.textContent ?? '',
        cleanup: () => {
            act(() => root.unmount());
            container.remove();
        },
    };
}

// Base UI's <Select.Value> renders the raw value unless the Root is given an
// `items` map (value -> label). The Radix->Base UI migration dropped `items`,
// so every trigger showed the value (e.g. "1d", a UUID) instead of the label.
// Both props are valid, so tsc/build could not catch it. Lock in that the
// trigger renders the selected item's label when `items` is supplied.
describe('select', () => {
    it('renders the selected item label in the trigger when items are provided', () => {
        const { triggerText, cleanup } = renderSelect({ items: ITEMS });
        expect(triggerText).toBe('In 24 hours');
        cleanup();
    });

    it('falls back to the raw value in the trigger without items (root cause)', () => {
        const { triggerText, cleanup } = renderSelect({});
        expect(triggerText).toBe('1d');
        cleanup();
    });
});
