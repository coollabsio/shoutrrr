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
