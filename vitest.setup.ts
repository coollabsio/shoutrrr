import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// jsdom ships no ResizeObserver, and components that measure themselves
// (Frimousse's emoji viewport, Base UI's positioners) throw on mount without
// it. A no-op stand-in is enough: nothing in jsdom ever resizes.
if (!('ResizeObserver' in globalThis)) {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
}

afterEach(() => {
    cleanup();
});
