export type FeedbackType = 'bug' | 'feedback' | 'question';

type FeedbackInput = {
    type: FeedbackType;
    message: string;
    url: string;
    browser: string;
    screenshot: Blob | null;
};

/** Assemble the multipart body the feedback endpoint expects. */
export function buildFeedbackFormData(input: FeedbackInput): FormData {
    const data = new FormData();
    data.append('type', input.type);
    data.append('message', input.message);
    data.append('url', input.url);
    data.append('browser', input.browser);

    if (input.screenshot) {
        data.append(
            'screenshot',
            new File([input.screenshot], 'screenshot.png', {
                type: 'image/png',
            }),
        );
    }

    return data;
}
