export type FeedbackType = 'bug' | 'feedback' | 'question';

type FeedbackInput = {
    type: FeedbackType;
    message: string;
    url: string;
    browser: string;
    screenshot: Blob | null;
    diagnostics: string | null;
};

export type FeedbackPayload = {
    type: FeedbackType;
    message: string;
    url: string;
    browser: string;
    screenshot?: File;
    diagnostics?: File;
};

/**
 * Build the submission payload as a plain object, not a `FormData` instance.
 *
 * `useHttp`'s `submit()` calls `hasFiles(transformedData)` then
 * `objectToFormData(transformedData)`, which iterates `for (const key in
 * source)` over the object's own enumerable properties. A real `FormData`
 * instance has zero own enumerable properties, so handing it a `FormData`
 * silently serializes to an empty body. A plain object with a `File`-valued
 * property is what `hasFiles`/`objectToFormData` actually expect (see
 * `resources/js/hooks/compose/use-media-uploads.ts`'s `imageHttp.transform(()
 * => ({ file }))`), so that's what this returns. The `screenshot` key is
 * omitted entirely when there's no blob, rather than set to `null`/`undefined`.
 */
export function buildFeedbackPayload(input: FeedbackInput): FeedbackPayload {
    const payload: FeedbackPayload = {
        type: input.type,
        message: input.message,
        url: input.url,
        browser: input.browser,
    };

    if (input.screenshot) {
        payload.screenshot = new File([input.screenshot], 'screenshot.png', {
            type: 'image/png',
        });
    }

    if (input.diagnostics) {
        payload.diagnostics = new File(
            [input.diagnostics],
            'diagnostics.json',
            {
                type: 'application/json',
            },
        );
    }

    return payload;
}
