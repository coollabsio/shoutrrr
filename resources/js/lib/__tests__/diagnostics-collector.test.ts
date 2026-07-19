import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    installDiagnostics,
    recordLog,
    recordNavigation,
    recordNetwork,
    resetDiagnostics,
    snapshotDiagnostics,
} from '../diagnostics-collector';

beforeEach(() => {
    resetDiagnostics();
});

afterEach(() => {
    vi.restoreAllMocks();
    resetDiagnostics();
});

describe('diagnostics-collector buffers', () => {
    it('snapshot has an ISO timestamp and the three buffers', () => {
        const snap = snapshotDiagnostics();

        expect(snap.capturedAt).toMatch(/^\d{4}-\d{2}-\d{2}T/);
        expect(snap.logs).toEqual([]);
        expect(snap.network).toEqual([]);
        expect(snap.navigation).toEqual([]);
    });

    it('records logs with level and stringified message', () => {
        recordLog('warn', ['boom', { code: 42 }]);

        const [entry] = snapshotDiagnostics().logs;
        expect(entry.level).toBe('warn');
        expect(entry.message).toContain('boom');
        expect(entry.message).toContain('42');
    });

    it('caps the log buffer at 100 and keeps the most recent', () => {
        for (let i = 0; i < 150; i++) {
            recordLog('log', [`n${i}`]);
        }

        const { logs } = snapshotDiagnostics();
        expect(logs).toHaveLength(100);
        expect(logs[0].message).toBe('n50');
        expect(logs[99].message).toBe('n149');
    });

    it('records network and navigation entries', () => {
        recordNetwork({
            at: 1,
            method: 'POST',
            url: 'https://app.test/api',
            status: 500,
            ok: false,
            durationMs: 12,
        });
        recordNavigation('/dashboard');

        const snap = snapshotDiagnostics();
        expect(snap.network[0]).toMatchObject({
            method: 'POST',
            status: 500,
            ok: false,
        });
        expect(snap.navigation[0].url).toBe('/dashboard');
    });
});

describe('diagnostics-collector install', () => {
    it('patches console.log so entries are captured, and forwards to the original', () => {
        const spy = vi.spyOn(console, 'log').mockImplementation(() => {});

        installDiagnostics();
        console.log('hello');

        expect(spy).toHaveBeenCalledWith('hello');
        const { logs } = snapshotDiagnostics();
        expect(
            logs.some((l) => l.message === 'hello' && l.level === 'log'),
        ).toBe(true);
    });

    it('wraps fetch and records request metadata (not bodies)', async () => {
        const fake = vi
            .fn()
            .mockResolvedValue(new Response('ok', { status: 201 }));
        vi.stubGlobal('fetch', fake);

        installDiagnostics();
        await fetch('https://app.test/api/posts', { method: 'POST' });

        const [entry] = snapshotDiagnostics().network;
        expect(entry).toMatchObject({
            method: 'POST',
            url: 'https://app.test/api/posts',
            status: 201,
            ok: true,
        });
        expect(entry.durationMs).not.toBeNull();
        // metadata only — the entry has no body/headers fields
        expect(entry).not.toHaveProperty('body');
        expect(entry).not.toHaveProperty('headers');
    });

    it('is idempotent — installing twice does not double-record', () => {
        vi.spyOn(console, 'log').mockImplementation(() => {});

        installDiagnostics();
        installDiagnostics();
        console.log('once');

        const hits = snapshotDiagnostics().logs.filter(
            (l) => l.message === 'once',
        );
        expect(hits).toHaveLength(1);
    });
});
