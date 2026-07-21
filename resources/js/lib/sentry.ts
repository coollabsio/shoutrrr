import * as Sentry from '@sentry/react';

/**
 * Browser Sentry config injected by the server at runtime (see the root blade
 * view). Delivered per-request rather than baked in at build time so a prebuilt
 * Docker image can be pointed at a DSN via env without rebuilding the bundle.
 */
type SentryConfig = {
    dsn: string;
    environment: string;
    release: string | null;
    traces_sample_rate: number;
};

declare global {
    interface Window {
        __sentry?: SentryConfig;
    }
}

/**
 * Initializes the browser Sentry SDK. No-op unless the server injected a DSN,
 * so instances without Sentry configured pay nothing beyond the guard.
 */
export function initSentry(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const config = window.__sentry;

    if (!config?.dsn) {
        return;
    }

    Sentry.init({
        dsn: config.dsn,
        environment: config.environment,
        release: config.release ?? undefined,
        integrations: [Sentry.browserTracingIntegration()],
        tracesSampleRate: config.traces_sample_rate,
        // Only attach tracing headers to same-origin (relative) requests; never
        // leak them to third-party hosts the app talks to.
        tracePropagationTargets: [/^\//],
    });
}
