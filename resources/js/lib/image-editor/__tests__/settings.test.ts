import { describe, expect, it } from 'vitest';

import { defaultSettings, normalizeSettings } from '../settings';

describe('edit settings', () => {
    it('defaultSettings is a valid version-1 settings object', () => {
        const s = defaultSettings();
        expect(s.version).toBe(1);
        expect(s.background.type).toBe('gradient');
        expect(s.crop).toBeNull();
        expect(s.zoom).toBe(1);
        expect(s.tilt).toEqual({ rotateX: 0, rotateY: 0 });
    });

    it('normalizeSettings clamps zoom to range and falls back when invalid', () => {
        expect(normalizeSettings({ zoom: 1.5 }).zoom).toBe(1.5);
        expect(normalizeSettings({ zoom: 5 }).zoom).toBe(2);
        expect(normalizeSettings({ zoom: 0.1 }).zoom).toBe(0.5);
        expect(normalizeSettings({ zoom: 'big' }).zoom).toBe(1);
    });

    it('normalizeSettings fills a partial object with defaults', () => {
        const s = normalizeSettings({ padding: 100 });
        expect(s.padding).toBe(100);
        expect(s.aspect).toBe('auto');
        expect(s.shadow).toBe(defaultSettings().shadow);
    });

    it('normalizeSettings rejects garbage and falls back to defaults', () => {
        expect(normalizeSettings(null).padding).toBe(defaultSettings().padding);
        expect(normalizeSettings('boom').version).toBe(1);
        expect(normalizeSettings({ shadow: 'wat' }).shadow).toBe(
            defaultSettings().shadow,
        );
        expect(normalizeSettings({ aspect: 'wat' }).aspect).toBe('auto');
    });

    it('normalizeSettings resolves a known gradient id and ignores unknown ids', () => {
        const known = normalizeSettings({
            background: { type: 'gradient', id: 'royal' },
        });
        expect(known.background.id).toBe('royal');
        const unknown = normalizeSettings({
            background: { type: 'gradient', id: 'nope' },
        });
        expect(unknown.background.id).toBe(defaultSettings().background.id);
    });

    it('normalizeSettings clamps a crop rect object and drops a non-object crop', () => {
        const withCrop = normalizeSettings({
            crop: { x: 1, y: 2, width: 3, height: 4 },
        });
        expect(withCrop.crop).toEqual({ x: 1, y: 2, width: 3, height: 4 });
        expect(normalizeSettings({ crop: 'x' }).crop).toBeNull();
    });
});
