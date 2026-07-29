import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, it } from 'vitest';

it('shows the consolidated reply count for conversation rows', () => {
    const source = readFileSync(
        resolve(import.meta.dirname, 'reply-stream.tsx'),
        'utf8',
    );

    expect(source).toContain('reply.reply_count');
    expect(source).toContain('replies');
});

it('animates only the rows the reader just pulled in', () => {
    const source = readFileSync(
        resolve(import.meta.dirname, 'reply-stream.tsx'),
        'utf8',
    );

    expect(source).toContain('revealedIds.includes(reply.id)');
    // Fade always, movement only when the reader accepts motion.
    expect(source).toContain('fade-in-0');
    expect(source).toContain('motion-safe:slide-in-from-top-2');
    expect(source).toContain('duration-200');
});
