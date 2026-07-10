/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { beforeAll, describe, expect, it } from 'vitest';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
});

// Base UI's Menu.GroupLabel throws "MenuGroupContext is missing" when rendered
// outside a Menu.Group. The app uses DropdownMenuLabel as a standalone section
// heading (user menu, connect-account menu, posts filters), so it must render
// without a surrounding group. Open the menu (Root `open`) so the popup content
// actually mounts — that is where the throw used to happen.
describe('dropdown-menu', () => {
    it('renders a standalone DropdownMenuLabel without a Group context throw', () => {
        const container = document.createElement('div');
        document.body.append(container);
        const root = createRoot(container);

        expect(() => {
            act(() => {
                root.render(
                    createElement(
                        DropdownMenu,
                        { open: true },
                        createElement(
                            DropdownMenuContent,
                            null,
                            createElement(
                                DropdownMenuLabel,
                                null,
                                'Section heading',
                            ),
                            createElement(DropdownMenuSeparator, null),
                            createElement(
                                DropdownMenuGroup,
                                null,
                                createElement(DropdownMenuItem, null, 'Item'),
                            ),
                            createElement(
                                DropdownMenuRadioGroup,
                                { value: 'a' },
                                createElement(
                                    DropdownMenuRadioItem,
                                    { value: 'a' },
                                    'A',
                                ),
                            ),
                        ),
                    ),
                );
            });
        }).not.toThrow();

        expect(document.body.textContent).toContain('Section heading');

        act(() => root.unmount());
        container.remove();
    });
});
