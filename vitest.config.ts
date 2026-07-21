import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': resolve(import.meta.dirname, './resources/js'),
        },
    },
    test: {
        projects: [
            {
                extends: true,
                test: {
                    name: 'node',
                    environment: 'node',
                    include: ['resources/js/**/*.test.ts'],
                },
            },
            {
                extends: true,
                test: {
                    name: 'jsdom',
                    environment: 'jsdom',
                    include: ['resources/js/**/*.test.tsx'],
                    setupFiles: ['./vitest.setup.ts'],
                },
            },
        ],
    },
});
