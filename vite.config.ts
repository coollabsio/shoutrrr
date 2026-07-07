import { cpSync } from 'node:fs';
import { networkInterfaces } from 'node:os';

import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

const tailscaleHost = (): string | undefined => {
    for (const addresses of Object.values(networkInterfaces())) {
        const address = addresses?.find(
            (address) =>
                address.family === 'IPv4' &&
                !address.internal &&
                address.address.startsWith('100.'),
        );

        if (address) {
            return address.address;
        }
    }

    return undefined;
};

const hmrHost = process.env.VITE_HMR_HOST ?? tailscaleHost() ?? 'localhost';

// Copy the emojibase `en` locale into public/ so Frimousse and the emoji
// typeahead fetch it same-origin. The app's CSP (connect-src 'self') blocks
// Frimousse's default jsdelivr CDN, so the data must be served from our origin.
function copyEmojiData() {
    const copy = () => {
        try {
            cpSync('node_modules/emojibase-data/en', 'public/emoji/en', {
                recursive: true,
            });
        } catch (error) {
            throw new Error(
                'copy-emoji-data: could not copy node_modules/emojibase-data/en ' +
                    'to public/emoji/en. Run `bun install` to restore the ' +
                    `emojibase-data dependency. (${(error as Error).message})`,
            );
        }
    };

    return {
        name: 'copy-emoji-data',
        buildStart: copy,
        configureServer: copy,
    };
}

export default defineConfig({
    server: {
        host: process.env.VITE_HOST ?? '0.0.0.0',
        cors: {
            origin: true,
        },
        hmr: hmrHost
            ? {
                  host: hmrHost,
              }
            : undefined,
    },
    plugins: [
        copyEmojiData(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        ...(process.env.SKIP_WAYFINDER_GENERATE
            ? []
            : [
                  wayfinder({
                      formVariants: true,
                  }),
              ]),
    ],
});
