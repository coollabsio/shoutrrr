import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, it } from 'vitest';

const source = readFileSync(resolve(import.meta.dirname, 'index.tsx'), 'utf8');

it('reserves space for the mobile sheet close button beside reply actions', () => {
    expect(source).toContain("reserveCloseButtonSpace && 'pr-14'");
    expect(source).toContain('reserveCloseButtonSpace');
});

it('shows disabled engagement platforms to end users', () => {
    expect(source).toContain('EngagementDisabledBanner');
    expect(source).toContain('Reply polling is temporarily disabled for');
    expect(source).toContain('bannerDisabledPlatforms');
});

it('keeps LinkedIn out of the banner when the community scope is off', () => {
    // LinkedIn engagement is off by default (restricted Community Management
    // scope) — that expected-off state must not surface as a temporary pause.
    expect(source).toContain('linkedinCommunityManagementEnabled');
    expect(source).toContain("label !== platformLabel('linkedin')");
});

it('runs reply actions as JSON requests, not Inertia visits', () => {
    // An Inertia visit follows the response into a fresh GET /engagement, which
    // drops the deferred `replies` scroll prop and blanks the left list.
    const source = readFileSync(
        resolve(import.meta.dirname, 'index.tsx'),
        'utf8',
    );

    expect(source).toContain('useHttp');
    expect(source).not.toContain('router.post(');
    expect(source).not.toContain('router.delete(');
});

it('overlays archived and responded rows onto the deferred replies prop', () => {
    const source = readFileSync(
        resolve(import.meta.dirname, 'index.tsx'),
        'utf8',
    );

    expect(source).toContain("overrides[r.id] !== 'archived'");
    expect(source).toContain("overrides[r.id] === 'responded'");
    // The unread badge rides on the shared `shell` prop; `replies` stays put.
    expect(source).toContain("router.reload({ only: ['shell'] })");
});

it('uses shared disabled platform label helpers', () => {
    const platformSource = readFileSync(
        resolve(import.meta.dirname, '../../lib/platforms.ts'),
        'utf8',
    );

    expect(source).not.toContain('const engagementPlatforms');
    expect(source).toContain("from '@/lib/platforms'");
    expect(platformSource).toContain('Object.keys(enabled)');
});

it('pins the engagement desk to the viewport and keeps the reply box in-flow', () => {
    expect(source).toContain('h-[calc(100svh-4rem)]');
    expect(source).toContain('overflow-hidden');
    expect(source).toContain('min-h-0 min-w-0 flex-col overflow-hidden');
    expect(source).toContain('replyEditorRef');
});

it('wires keyboard shortcuts for triage, archive, open comment, and reply focus', () => {
    expect(source).toContain('engagementShortcut');
    expect(source).toContain('adjacentIndex');
    expect(source).toContain('nextAfterArchive');
    expect(source).toContain("case 'archive'");
    expect(source).toContain("case 'open'");
    expect(source).toContain("case 'reply'");
    expect(source).toContain('openSelectedComment');
    expect(source).toContain('replyEditorRef.current?.focus()');
    expect(source).toContain('<Kbd>A</Kbd>');
    expect(source).toContain("keys={['↑', '↓']}");
    expect(source).toContain("keys={['O']}");
    expect(source).toContain("keys={['R']}");
    expect(source).toContain('label="open comment"');
});

it('consolidates open targets into an Open in dropdown', () => {
    expect(source).toContain('Open in');
    expect(source).toContain('Open comment on {platformName}');
    expect(source).toContain('Open post on {platformName}');
    expect(source).toContain('Open in Shoutrrr');
    expect(source).toContain('commentOnPlatformUrl');
    expect(source).toContain('postOnPlatformUrl');
    expect(source).toContain('postInShoutrrrUrl');
    expect(source).not.toContain('>Post<');
});
