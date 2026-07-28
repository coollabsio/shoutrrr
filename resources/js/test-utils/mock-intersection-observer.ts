import { vi } from 'vitest';

/**
 * A controllable stand-in for the browser's IntersectionObserver, which jsdom
 * does not implement. Records every instance created so a test can drive
 * `trigger()` to simulate an observed element entering or leaving the viewport,
 * independent of real layout.
 *
 * Install it per suite with `vi.stubGlobal('IntersectionObserver', MockIntersectionObserver)`
 * and reset `MockIntersectionObserver.instances` in `beforeEach`.
 */
export class MockIntersectionObserver {
    static instances: MockIntersectionObserver[] = [];
    callback: IntersectionObserverCallback;
    disconnect = vi.fn();

    constructor(callback: IntersectionObserverCallback) {
        this.callback = callback;
        MockIntersectionObserver.instances.push(this);
    }

    observe() {}
    unobserve() {}

    trigger(isIntersecting: boolean) {
        this.callback(
            [{ isIntersecting } as IntersectionObserverEntry],
            this as unknown as IntersectionObserver,
        );
    }
}
