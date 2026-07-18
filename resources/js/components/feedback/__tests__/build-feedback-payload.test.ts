import { describe, expect, it } from 'vitest';

import { buildFeedbackPayload } from '../build-feedback-payload';

// The payload MUST be a plain object, never a `FormData` instance: useHttp's
// submit() runs objectToFormData(transformedData), which only reads own
// enumerable properties — a real FormData instance has none, so passing one
// through silently serializes to an empty body (every field lost). These
// assertions read fields directly off the returned object to guard against
// that regression.
describe('buildFeedbackPayload', () => {
    it('returns a plain object with core fields and omits screenshot when null', () => {
        const payload = buildFeedbackPayload({
            type: 'bug',
            message: 'It broke',
            url: 'https://app.test/x',
            browser: 'UA',
            screenshot: null,
        });

        expect(payload).not.toBeInstanceOf(FormData);
        expect(payload.type).toBe('bug');
        expect(payload.message).toBe('It broke');
        expect(payload.url).toBe('https://app.test/x');
        expect(payload.browser).toBe('UA');
        expect('screenshot' in payload).toBe(false);
    });

    it('attaches the screenshot as a File when present', () => {
        const blob = new Blob(['x'], { type: 'image/png' });
        const payload = buildFeedbackPayload({
            type: 'feedback',
            message: 'nice',
            url: 'u',
            browser: 'UA',
            screenshot: blob,
        });

        expect(payload).not.toBeInstanceOf(FormData);
        expect(payload.screenshot).toBeInstanceOf(File);
        expect(payload.screenshot?.name).toBe('screenshot.png');
    });
});
