<?php

/**
 * Browser Sentry SDK configuration.
 *
 * Kept separate from config/sentry.php because sentry-laravel validates that
 * file against the PHP SDK's strict option list and rejects unknown keys. These
 * values are surfaced to the client at runtime (see the root blade view and
 * resources/js/lib/sentry.ts) rather than baked in at build time, so a prebuilt
 * Docker image can be pointed at a DSN via env without rebuilding the bundle.
 *
 * A Sentry DSN is a public value, so exposing it to the browser is expected. The
 * frontend uses its own DSN (a separate Sentry project) so browser and server
 * issues route independently. An empty DSN disables the browser SDK entirely.
 */

// Mirror the backend's release/environment derivation (see config/sentry.php):
// tag events with the running app version, falling back to the VERSION file the
// frontend already reads, and default the environment to APP_ENV.
$release = env('SENTRY_RELEASE') ?: (is_file(base_path('VERSION'))
    ? (trim((string) file_get_contents(base_path('VERSION'))) ?: null)
    : null);

return [
    'dsn' => env('SENTRY_FRONTEND_DSN'),
    'environment' => env('SENTRY_ENVIRONMENT') ?: env('APP_ENV', 'production'),
    'release' => $release,
    'traces_sample_rate' => env('SENTRY_FRONTEND_TRACES_SAMPLE_RATE') === null
        ? 0.2
        : (float) env('SENTRY_FRONTEND_TRACES_SAMPLE_RATE'),
];
