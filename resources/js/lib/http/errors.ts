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

/** `useHttp` resolves with nothing on a 422: treat that as the failure it is. */
export function requireResponse<T>(response: T | undefined): T {
    if (response === undefined) {
        throw new Error('The server rejected the request.');
    }

    return response;
}
