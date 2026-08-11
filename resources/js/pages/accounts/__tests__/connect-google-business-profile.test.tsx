import { describe, expect, it } from 'vitest';

import {
    buildGoogleBusinessProfileSelection,
    canConnectGoogleBusinessProfileLocations,
} from '../connect-google-business-profile';

describe('Google Business Profile location selection', () => {
    it('only submits selected locations eligible for Local Posts', () => {
        expect(
            buildGoogleBusinessProfileSelection(
                { eligible: true, ineligible: true },
                [
                    {
                        key: 'eligible',
                        accountResourceName: 'accounts/one',
                        locationResourceName: 'accounts/one/locations/eligible',
                        title: 'Eligible',
                        storeCode: null,
                        addressLabel: null,
                        mapsUri: null,
                        canOperateLocalPost: true,
                        readinessIssues: [],
                    },
                    {
                        key: 'ineligible',
                        accountResourceName: 'accounts/one',
                        locationResourceName: 'accounts/one/locations/ineligible',
                        title: 'Ineligible',
                        storeCode: null,
                        addressLabel: null,
                        mapsUri: null,
                        canOperateLocalPost: false,
                        readinessIssues: [],
                    },
                ],
            ),
        ).toEqual(['eligible']);
    });

    it('requires both an eligible selection and explicit consent before enabling connect', () => {
        expect(canConnectGoogleBusinessProfileLocations([], false)).toBe(false);
        expect(
            canConnectGoogleBusinessProfileLocations(['eligible'], false),
        ).toBe(false);
        expect(canConnectGoogleBusinessProfileLocations([], true)).toBe(false);
        expect(
            canConnectGoogleBusinessProfileLocations(['eligible'], true),
        ).toBe(true);
    });
});
