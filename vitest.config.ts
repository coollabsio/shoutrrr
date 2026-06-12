import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.ts'],
    },
    resolve: {
        alias: {
            '@': resolve(import.meta.dirname, './resources/js'),
        },
    },
});
