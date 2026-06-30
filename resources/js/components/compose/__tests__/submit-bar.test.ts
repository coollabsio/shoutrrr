import { describe, expect, it } from 'vitest';

import { isSubmitShortcut, shouldAllowSubmit } from '../submit-bar';

const baseEvent = {
    altKey: false,
    ctrlKey: false,
    key: 'Enter',
    metaKey: false,
    shiftKey: false,
};

describe('isSubmitShortcut', () => {
    it('matches plain command or control enter only', () => {
        expect(isSubmitShortcut({ ...baseEvent, metaKey: true })).toBe(true);
        expect(isSubmitShortcut({ ...baseEvent, ctrlKey: true })).toBe(true);
        expect(
            isSubmitShortcut({ ...baseEvent, metaKey: true, key: 'N' }),
        ).toBe(false);
        expect(
            isSubmitShortcut({ ...baseEvent, metaKey: true, shiftKey: true }),
        ).toBe(false);
        expect(
            isSubmitShortcut({ ...baseEvent, metaKey: true, altKey: true }),
        ).toBe(false);
    });
});

describe('shouldAllowSubmit', () => {
    it('uses the same guard as the publish button', () => {
        expect(
            shouldAllowSubmit({
                disabled: false,
                processing: false,
                queueDisabled: false,
                trayMode: 'now',
                uploading: false,
            }),
        ).toBe(true);
        expect(
            shouldAllowSubmit({
                disabled: true,
                processing: false,
                queueDisabled: false,
                trayMode: 'now',
                uploading: false,
            }),
        ).toBe(false);
        expect(
            shouldAllowSubmit({
                disabled: false,
                processing: false,
                queueDisabled: true,
                trayMode: 'queue',
                uploading: false,
            }),
        ).toBe(false);
        expect(
            shouldAllowSubmit({
                disabled: false,
                processing: false,
                queueDisabled: true,
                trayMode: 'pick',
                uploading: false,
            }),
        ).toBe(true);
    });
});
