import { describe, expect, it } from 'vitest';

import { redactInertiaProps } from '../redact-inertia-props';

describe('redactInertiaProps', () => {
    it('redacts credential-like keys but keeps benign ones', () => {
        const out = redactInertiaProps({
            auth: { user: { id: 1, name: 'Ada', email: 'ada@test.co' } },
            csrf_token: 'abc',
            apiKey: 'sk-123',
            flash: { plainTextApiKey: 'sk-xyz' },
            password: 'hunter2',
            author_id: 42, // contains "auth" but must NOT be redacted
            title: 'Hello',
        }) as Record<string, unknown>;

        const auth = out.auth as { user: { email: string } };
        expect(auth.user.email).toBe('ada@test.co');
        expect(out.author_id).toBe(42);
        expect(out.title).toBe('Hello');
        expect(out.csrf_token).toBe('[redacted]');
        expect(out.apiKey).toBe('[redacted]');
        expect((out.flash as Record<string, unknown>).plainTextApiKey).toBe(
            '[redacted]',
        );
        expect(out.password).toBe('[redacted]');
    });

    it('caps long strings and large arrays', () => {
        const out = redactInertiaProps({
            long: 'x'.repeat(1000),
            big: Array.from({ length: 100 }, (_, i) => i),
        }) as { long: string; big: unknown[] };

        expect(out.long.length).toBeLessThan(1000);
        expect(out.long).toContain('1000 chars');
        expect(out.big).toHaveLength(51); // 50 items + overflow marker
        expect(String(out.big[50])).toContain('more');
    });

    it('truncates beyond the max depth', () => {
        let deep: unknown = 'bottom';
        for (let i = 0; i < 10; i++) {
            deep = { nested: deep };
        }

        expect(JSON.stringify(redactInertiaProps(deep))).toContain('[object]');
    });

    it('passes primitives and null through unchanged', () => {
        expect(redactInertiaProps(null)).toBeNull();
        expect(redactInertiaProps(5)).toBe(5);
        expect(redactInertiaProps('hi')).toBe('hi');
    });
});
