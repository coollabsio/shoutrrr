import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, it } from 'vitest';

const source = readFileSync(resolve(import.meta.dirname, 'index.tsx'), 'utf8');

it('runs conversation actions as JSON requests, not Inertia visits', () => {
    // An Inertia visit follows the response into a fresh GET /messages, which
    // drops the deferred `conversations` scroll prop and blanks the left list.
    expect(source).toContain('useHttp');
    expect(source).not.toContain('router.post(');
    expect(source).not.toContain('router.delete(');
    expect(source).not.toContain('router.visit(');
});

it('refreshes only the shared unread badge after a triage action', () => {
    // `conversations` is deliberately absent from `only`, so the deferred list
    // keeps its data instead of falling back to the skeleton.
    expect(source).toContain(
        "router.reload({ only: ['shell.unreadMessages'] })",
    );
    expect(source).not.toContain("'conversations'],");
});

it('overlays archived, read, and responded rows onto the deferred prop', () => {
    expect(source).toContain('const [overrides, setOverrides]');
    expect(source).toContain('!overrides[c.id]?.archived');
    expect(source).toContain('archived: true');
    expect(source).toContain('unread_count: 0');
    expect(source).toContain('last_message_preview: preview');
});

it('drops stale overrides when the filter changes', () => {
    // reset:['conversations'] brings a different row set; overrides keyed to
    // the old set would wrongly hide rows in, say, the archived view.
    expect(source).toContain('prevFilterKey');
    expect(source).toContain('setOverrides({})');
});

it('wires keyboard shortcuts for triage, archive, and reply focus', () => {
    expect(source).toContain('messagesShortcut');
    expect(source).toContain('adjacentIndex');
    expect(source).toContain('nextAfterArchive');
    expect(source).toContain("case 'archive'");
    expect(source).toContain("case 'reply'");
    expect(source).toContain('messageEditorRef.current?.focus()');
    expect(source).toContain("keys={['↑', '↓']}");
    expect(source).toContain("keys={['A']}");
    expect(source).toContain("keys={['R']}");
});

it('reserves space for the mobile sheet close button beside the header actions', () => {
    expect(source).toContain("reserveCloseButtonSpace && 'pr-14'");
    expect(source).toContain('reserveCloseButtonSpace');
});

it('pins the messages desk to the viewport, allowing for the inset margin', () => {
    // The sidebar `variant="inset"` gives the <main> an md+ `m-2` (1rem of
    // vertical margin), so the desk subtracts an extra rem on md+ to avoid
    // spilling a window scrollbar under the shortcut bar.
    expect(source).toContain('h-[calc(100svh-4rem)]');
    expect(source).toContain('md:h-[calc(100svh-5rem)]');
    expect(source).toContain('overflow-hidden');
    expect(source).toContain('min-h-0 min-w-0 flex-col overflow-hidden');
    expect(source).toContain('messageEditorRef');
});

it('gives each conversation a fresh draft box', () => {
    // Remounting on conversation change clears local text and attached media
    // instead of carrying the previous conversation's draft over.
    expect(source).toContain('key={selected.id}');
});

it('uses the shared inbox helpers rather than page-local copies', () => {
    expect(source).toContain("from './helpers'");
    expect(source).not.toContain('function adjacentIndex');
    expect(source).not.toContain('function nextAfterArchive');

    const helperSource = readFileSync(
        resolve(import.meta.dirname, 'helpers.ts'),
        'utf8',
    );

    expect(helperSource).toContain("from '@/lib/inbox/helpers'");
});
