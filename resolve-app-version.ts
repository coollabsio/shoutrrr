import { execSync } from 'node:child_process';

/**
 * Resolve the app version for the frontend bundle at build time.
 *
 * Precedence:
 *  1. `APP_VERSION` env — set by the release pipeline from the published git
 *     tag (the production image has no `.git`, so this is the only source there).
 *  2. `git describe` — local dev convenience so the sidebar badge shows a
 *     meaningful version without a committed VERSION file.
 *  3. Empty string — build contexts with neither (badge simply renders blank).
 */
export function resolveAppVersion(): string {
    const fromEnv = process.env.APP_VERSION?.trim();

    if (fromEnv) {
        return fromEnv;
    }

    try {
        return execSync('git describe --tags --always', {
            stdio: ['ignore', 'pipe', 'ignore'],
        })
            .toString()
            .trim();
    } catch {
        return '';
    }
}
