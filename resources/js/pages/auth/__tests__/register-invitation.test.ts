import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = () =>
    readFileSync(
        resolve(process.cwd(), 'resources/js/pages/auth/register.tsx'),
        'utf8',
    );

const loginSource = () =>
    readFileSync(
        resolve(process.cwd(), 'resources/js/pages/auth/login.tsx'),
        'utf8',
    );

describe('invitation registration form', () => {
    it('prefills and locks the invited email address', () => {
        const register = source();

        expect(register).toContain('invitationEmail?: string | null;');
        expect(register).toContain(
            'defaultValue={invitationEmail ?? undefined}',
        );
        expect(register).toContain('readOnly={Boolean(invitationEmail)}');
    });

    it('keeps the invitation token on register and login routes', () => {
        const register = source();
        const login = loginSource();

        expect(register).toContain('store.form(');
        expect(register).toContain('login(');
        expect(register.match(/query: \{ invitation \}/g)).toHaveLength(2);
        expect(login).toContain('register(');
        expect(login.match(/query: \{ invitation \}/g)).toHaveLength(1);
    });
});
