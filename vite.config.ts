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

const hmrHost = process.env.VITE_HMR_HOST ?? tailscaleHost();

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
