/**
 * Reads Laravel's `XSRF-TOKEN` cookie for hand-rolled `fetch` calls that need
 * CSRF protection outside `useHttp` (which reads this cookie internally).
 * Centralizing the read keeps a single CSRF-cookie pattern in the codebase
 * even when a raw `fetch` is still required for its own error-surfacing needs.
 */
export function xsrfHeader(): Record<string, string> {
    const token = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '';

    return { 'X-XSRF-TOKEN': decodeURIComponent(token) };
}
