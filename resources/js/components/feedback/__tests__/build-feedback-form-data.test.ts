import { describe, expect, it } from 'vitest';

import { buildFeedbackFormData } from '../build-feedback-form-data';

describe('buildFeedbackFormData', () => {
    it('includes core fields and omits screenshot when null', () => {
        const fd = buildFeedbackFormData({
            type: 'bug',
            message: 'It broke',
            url: 'https://app.test/x',
            browser: 'UA',
            screenshot: null,
        });

        expect(fd.get('type')).toBe('bug');
        expect(fd.get('message')).toBe('It broke');
        expect(fd.get('url')).toBe('https://app.test/x');
        expect(fd.get('browser')).toBe('UA');
        expect(fd.has('screenshot')).toBe(false);
    });

    it('appends the screenshot blob when present', () => {
        const blob = new Blob(['x'], { type: 'image/png' });
        const fd = buildFeedbackFormData({
            type: 'feedback',
            message: 'nice',
            url: 'u',
            browser: 'UA',
            screenshot: blob,
        });

        expect(fd.get('screenshot')).toBeInstanceOf(File);
    });
});
