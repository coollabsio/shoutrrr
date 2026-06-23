import { isValidElement } from 'react';
import { describe, expect, it } from 'vitest';

import { roleIcon } from '../member-role-helpers';

const roles = ['owner', 'admin', 'member'];

describe('roleIcon', () => {
    it('uses neutral role icons everywhere', () => {
        for (const role of roles) {
            const icon = roleIcon(role);

            expect(isValidElement(icon)).toBe(true);
            expect(icon.props.className).toBe('size-4');
        }
    });
});
