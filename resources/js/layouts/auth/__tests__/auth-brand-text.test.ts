import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const readSource = (file: string) =>
    readFileSync(resolve(process.cwd(), file), 'utf8');

describe('auth logo brand text', () => {
    it('shows Shoutrrr next to the logo when requested by login and register pages', () => {
        const layout = readSource(
            'resources/js/layouts/auth/auth-simple-layout.tsx',
        );
        const login = readSource('resources/js/pages/auth/login.tsx');
        const register = readSource('resources/js/pages/auth/register.tsx');

        expect(layout).toContain('brandText');
        expect(layout).toContain('{brandText && (');
        expect(layout).toContain('{brandText}');
        expect(login).toContain("brandText: 'Shoutrrr'");
        expect(register).toContain("brandText: 'Shoutrrr'");
    });
});
