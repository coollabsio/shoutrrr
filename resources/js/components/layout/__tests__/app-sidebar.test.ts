import { describe, expect, it } from 'vitest';

import { workspaceSettingsLabel } from '../app-sidebar';

describe('workspaceSettingsLabel', () => {
    it('identifies the sidebar destination as workspace settings', () => {
        expect(workspaceSettingsLabel).toBe('Workspace settings');
    });
});
