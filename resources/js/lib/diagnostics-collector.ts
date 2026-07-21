import { router } from '@inertiajs/react';

/**
 * Lightweight, always-on diagnostics collector: bounded ring buffers of the
 * console output, network requests, navigation, and uncaught errors leading up
 * to the moment a user opens the feedback widget. Snapshotted at report time
 * and attached to the report so a bug is reproducible without a back-and-forth.
 *
 * Privacy: this captures request/navigation METADATA only — never request or
 * response bodies, never headers (no auth tokens/cookies). Host redaction for
 * self-hosted instances is applied server-side (see FeedbackController), so the
 * collector stores raw URLs and does no redaction itself.
 */

type ConsoleLevel = 'log' | 'info' | 'warn' | 'error' | 'debug';

export type DiagnosticsLevel = ConsoleLevel | 'unhandled';

export type DiagnosticsLogEntry = {
    at: number;
    level: DiagnosticsLevel;
    message: string;
};

export type DiagnosticsNetworkEntry = {
    at: number;
    method: string;
    url: string;
    status: number | null;
    ok: boolean;
    durationMs: number | null;
    error?: string;
};

export type DiagnosticsNavigationEntry = {
    at: number;
    url: string;
};

export type DiagnosticsSnapshot = {
    capturedAt: string;
    logs: DiagnosticsLogEntry[];
    network: DiagnosticsNetworkEntry[];
    navigation: DiagnosticsNavigationEntry[];
};

const LOG_CAP = 100;
const NETWORK_CAP = 50;
const NAVIGATION_CAP = 30;
const MESSAGE_MAX = 2000;

const logs: DiagnosticsLogEntry[] = [];
const network: DiagnosticsNetworkEntry[] = [];
const navigation: DiagnosticsNavigationEntry[] = [];

let installed = false;

function push<T>(buffer: T[], entry: T, cap: number): void {
    buffer.push(entry);
    if (buffer.length > cap) {
        buffer.shift();
    }
}

function serializeArg(arg: unknown): string {
    if (typeof arg === 'string') {
        return arg;
    }
    if (arg instanceof Error) {
        return `${arg.name}: ${arg.message}`;
    }
    try {
        return JSON.stringify(arg) ?? String(arg);
    } catch {
        return String(arg);
    }
}

/** @internal — exported for the installers below and for tests. */
export function recordLog(level: DiagnosticsLevel, args: unknown[]): void {
    push(
        logs,
        {
            at: Date.now(),
            level,
            message: args.map(serializeArg).join(' ').slice(0, MESSAGE_MAX),
        },
        LOG_CAP,
    );
}

/** @internal */
export function recordNetwork(entry: DiagnosticsNetworkEntry): void {
    push(network, entry, NETWORK_CAP);
}

/** @internal */
export function recordNavigation(url: string): void {
    push(navigation, { at: Date.now(), url }, NAVIGATION_CAP);
}

/** Clears all buffers and the installed flag. For tests only. */
export function resetDiagnostics(): void {
    logs.length = 0;
    network.length = 0;
    navigation.length = 0;
    installed = false;
}

export function snapshotDiagnostics(): DiagnosticsSnapshot {
    return {
        capturedAt: new Date().toISOString(),
        logs: [...logs],
        network: [...network],
        navigation: [...navigation],
    };
}

function urlOf(input: RequestInfo | URL): string {
    if (typeof input === 'string') {
        return input;
    }
    if (input instanceof URL) {
        return input.toString();
    }
    return input.url;
}

function methodOf(input: RequestInfo | URL, init?: RequestInit): string {
    if (init?.method) {
        return init.method.toUpperCase();
    }
    if (input instanceof Request) {
        return input.method.toUpperCase();
    }
    return 'GET';
}

function installConsole(): void {
    const levels: ConsoleLevel[] = ['log', 'info', 'warn', 'error', 'debug'];

    for (const level of levels) {
        const original = console[level].bind(console);
        console[level] = (...args: unknown[]) => {
            // Forward to the real console first, then record — recording must
            // never suppress or break the user's own console call.
            original(...args);
            try {
                recordLog(level, args);
            } catch {
                // Diagnostics are best-effort; never let them throw app-wide.
            }
        };
    }
}

function installFetch(): void {
    if (typeof fetch === 'undefined') {
        return;
    }

    const original = fetch;
    globalThis.fetch = async (
        input: RequestInfo | URL,
        init?: RequestInit,
    ): Promise<Response> => {
        const startedAt = Date.now();
        const method = methodOf(input, init);
        const url = urlOf(input);

        try {
            const response = await original(input, init);
            recordNetwork({
                at: startedAt,
                method,
                url,
                status: response.status,
                ok: response.ok,
                durationMs: Date.now() - startedAt,
            });
            return response;
        } catch (error) {
            recordNetwork({
                at: startedAt,
                method,
                url,
                status: null,
                ok: false,
                durationMs: Date.now() - startedAt,
                error: error instanceof Error ? error.message : String(error),
            });
            throw error;
        }
    };
}

function installXhr(): void {
    if (typeof XMLHttpRequest === 'undefined') {
        return;
    }

    const proto = XMLHttpRequest.prototype;
    // Stored unbound on purpose — re-invoked below with each XHR instance's own
    // `this` via call/apply, which is exactly what the wrapper needs.
    // oxlint-disable-next-line typescript/unbound-method
    const originalOpen = proto.open;
    // oxlint-disable-next-line typescript/unbound-method
    const originalSend = proto.send;

    type Tracked = { __method?: string; __url?: string; __startedAt?: number };

    proto.open = function (
        this: XMLHttpRequest & Tracked,
        method: string,
        url: string | URL,
        ...rest: unknown[]
    ) {
        this.__method = method.toUpperCase();
        this.__url = typeof url === 'string' ? url : url.toString();
        // @ts-expect-error — forwarding the native variadic signature verbatim.
        return originalOpen.call(this, method, url, ...rest);
    };

    proto.send = function (this: XMLHttpRequest & Tracked, ...args: unknown[]) {
        this.__startedAt = Date.now();
        this.addEventListener('loadend', () => {
            recordNetwork({
                at: this.__startedAt ?? Date.now(),
                method: this.__method ?? 'GET',
                url: this.__url ?? '',
                status: this.status === 0 ? null : this.status,
                ok: this.status >= 200 && this.status < 300,
                durationMs: Date.now() - (this.__startedAt ?? Date.now()),
                error: this.status === 0 ? 'network error' : undefined,
            });
        });
        // @ts-expect-error — forwarding the native variadic signature verbatim.
        return originalSend.apply(this, args);
    };
}

function installErrorHandlers(): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.addEventListener('error', (event) => {
        recordLog('unhandled', [event.message ?? 'Uncaught error']);
    });

    window.addEventListener('unhandledrejection', (event) => {
        recordLog('unhandled', [
            `Unhandled rejection: ${serializeArg(event.reason)}`,
        ]);
    });
}

function installNavigation(): void {
    router.on('navigate', (event) => {
        const page = (event as { detail?: { page?: { url?: string } } }).detail
            ?.page;
        recordNavigation(page?.url ?? '');
    });
}

/**
 * Install the collectors once, as early as possible in app bootstrap. Safe to
 * call multiple times — subsequent calls are no-ops.
 */
export function installDiagnostics(): void {
    if (installed) {
        return;
    }
    installed = true;

    installConsole();
    installFetch();
    installXhr();
    installErrorHandlers();
    installNavigation();
}
