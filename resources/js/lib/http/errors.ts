import { toast } from 'sonner';

/**
 * Message from a failed `useHttp` request, read from `onHttpException`'s
 * response. That response carries the **raw body string**, and a non-2xx can
 * come from anywhere in the stack — a proxy's HTML 502 page must not throw
 * inside an error handler, so parsing is guarded and falls back.
 */
export function actionErrorMessage(
    response: { data: string },
    fallback: string,
): string {
    try {
        const parsed: unknown = JSON.parse(response.data);
        if (parsed !== null && typeof parsed === 'object') {
            const { message } = parsed as { message?: unknown };
            if (typeof message === 'string' && message.trim() !== '') {
                return message;
            }
        }
    } catch {
        // Not JSON (e.g. a gateway HTML error page) — use the fallback.
    }

    return fallback;
}

/**
 * First message from `useHttp`'s `onError` bag. That callback is the only one a
 * 422 reaches: `onHttpException` never fires, and the request resolves empty.
 */
export function firstValidationError(
    errors: Record<string, unknown>,
    fallback: string,
): string {
    const first = Object.values(errors)[0];

    return typeof first === 'string' && first.trim() !== '' ? first : fallback;
}

/** Callbacks that tell the user why a `useHttp` request failed. */
export function failureToasts(fallback: string) {
    return {
        onError: (errors: Record<string, unknown>) => {
            toast.error(firstValidationError(errors, fallback));
        },
        onHttpException: (response: { data: string }) => {
            toast.error(actionErrorMessage(response, fallback));
        },
        onNetworkError: () => {
            toast.error('Could not reach the server.');
        },
    };
}

/** How a request ended: its value, or not ok when it failed. */
export type Settled<T> = { ok: true; value: T } | { ok: false };

/**
 * Waits for a request and reports how it ended, instead of throwing.
 *
 * A `useHttp` request calls its `onError`, `onHttpException`, or
 * `onNetworkError` callback first, and then still rejects; on a 422 it
 * resolves with nothing. The callbacks tell the user what went wrong, so the
 * caller only needs to know whether the request worked.
 */
export async function settle<T>(
    request: Promise<T | undefined>,
): Promise<Settled<T>> {
    try {
        const value = await request;

        return value === undefined ? { ok: false } : { ok: true, value };
    } catch {
        return { ok: false };
    }
}
