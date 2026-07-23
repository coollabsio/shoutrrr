import { cpSync, unlinkSync } from 'node:fs';
import path from 'node:path';

import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, loadEnv, type Plugin } from 'vite';

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

/**
 * Free HTTPS tunnels (trycloudflare) only proxy Laravel. Vite stays plain HTTP
 * on :5173+, which browsers block as mixed content. Drop public/hot so Laravel
 * serves public/build instead. Run `bun run build` after frontend changes.
 * Must be registered after laravel-vite-plugin so it runs after hot is written.
 */
function disableHotFileForHttpsAppUrl(appUrl: URL): Plugin | null {
    if (appUrl.protocol !== 'https:') {
        return null;
    }

    const hotFile = path.resolve('public/hot');
    const removeHot = () => {
        try {
            unlinkSync(hotFile);
        } catch {
            // absent is fine
        }
    };

    return {
        name: 'disable-hot-file-for-https-app-url',
        buildStart: removeHot,
        configureServer(server) {
            const onListen = () => {
                removeHot();
                // laravel-vite-plugin writes hot on the same tick; clear again after.
                setTimeout(removeHot, 0);
                setTimeout(removeHot, 100);
            };

            if (server.httpServer?.listening) {
                onListen();
            } else {
                server.httpServer?.once('listening', onListen);
            }
        },
    };
}

export default defineConfig(({ mode }) => {
    const environment = {
        ...loadEnv(mode, process.cwd(), ''),
        ...process.env,
    };

    const appUrl = new URL(environment.APP_URL || 'http://localhost');
    // Prefer VITE_HMR_HOST when accessing the app via a hostname other than
    // APP_URL (e.g. APP_URL=http://localhost but browser opens http://oracle-arm:8000).
    // Empty string in .env counts as unset. Never default HMR to an HTTPS APP_URL
    // host — that port is not tunnelled and would be mixed content.
    const configuredHmrHost = (environment.VITE_HMR_HOST || '').trim();
    const hmrHost =
        configuredHmrHost ||
        (appUrl.protocol === 'https:' ? 'localhost' : appUrl.hostname);
    const vitePort = Number(environment.VITE_PORT || 5173);
    const disableHotPlugin = disableHotFileForHttpsAppUrl(appUrl);

    return {
        server: {
            // Listen on all interfaces so LAN / hostname access works.
            host: '0.0.0.0',
            port: vitePort,
            // Fall back to the next free port when the preferred one is taken.
            // Do not set origin/clientPort to a fixed preferred port — those would
            // point browsers at a dead port after fallback. laravel-vite-plugin
            // builds public/hot from hmr.host + the actual bound port.
            strictPort: false,
            // Allow the page origin (localhost, oracle-arm, LAN IP, …).
            cors: true,
            hmr: {
                host: hmrHost,
            },
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
            ...(disableHotPlugin ? [disableHotPlugin] : []),
            inertia(),
            react({
                babel: {
                    plugins: ['babel-plugin-react-compiler'],
                },
            }),
            tailwindcss(),
            ...(environment.SKIP_WAYFINDER_GENERATE
                ? []
                : [
                      wayfinder({
                          formVariants: true,
                      }),
                  ]),
        ],
    };
});
