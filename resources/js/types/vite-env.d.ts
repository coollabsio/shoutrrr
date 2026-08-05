/// <reference types="vite/client" />

/**
 * App version baked into the bundle at build time via Vite `define`.
 * Resolved from the release git tag (or `git describe` locally).
 * See resolve-app-version.ts.
 */
declare const __APP_VERSION__: string;
