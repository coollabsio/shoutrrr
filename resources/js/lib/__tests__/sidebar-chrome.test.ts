import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const css = readFileSync(
    resolve(import.meta.dirname, '../../../css/app.css'),
    'utf8',
);

const chrome = css
    .slice(css.indexOf('Default-dark sidebar chrome'))
    .replace(/\/\*[\s\S]*?\*\//g, '');

describe('default dark sidebar chrome', () => {
    it('washes only the default dark theme with Shoutrrr green', () => {
        expect(chrome).toContain("html.dark:not([data-theme='custom'])");
        expect(chrome).toContain('--sidebar: #0d1307');
        expect(chrome).toContain('--sidebar-brand-light: #81b744');
        expect(chrome).toContain(
            '--sidebar-brand-wash: color-mix(in srgb, var(--primary) 9%, transparent)',
        );
        expect(chrome).toContain('transparent 520px');
        expect(chrome).not.toContain('#120e1a');
        expect(chrome).not.toContain('#6b16ed');
        expect(chrome).not.toContain('#a391ff');
        expect(chrome).not.toContain('163 145 255');
    });

    it('keeps light mode on a neutral chrome edge', () => {
        expect(chrome).toContain("html:not(.dark) [data-slot='sidebar-inner']");
        expect(chrome).toContain('rgb(0 0 0 / 6%)');
        expect(chrome).not.toContain(
            "html:not(.dark) [data-slot='sidebar-header']",
        );
    });
});
