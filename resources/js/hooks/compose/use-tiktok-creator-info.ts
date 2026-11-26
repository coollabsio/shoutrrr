import { useCallback, useEffect, useState } from 'react';

import TikTokCreatorInfoController from '@/actions/App/Http/Controllers/ConnectedAccounts/TikTokCreatorInfoController';
import type { TikTokCreatorInfo, TikTokCreatorState } from '@/types/compose';

type CreatorInfoResponse =
    | { available: true; creator: TikTokCreatorInfo }
    | { available: false; message: string };

const UNREACHABLE: TikTokCreatorState = {
    status: 'unavailable',
    message: 'Could not check this TikTok account. Try again in a moment.',
};

/**
 * Live creator info for each TikTok account in the destination. TikTok asks
 * clients to read it fresh whenever a post is composed, so it is fetched once
 * per account per composer session (and on demand via `reload`), never cached
 * across page loads.
 */
export function useTikTokCreatorInfo(accountIds: string[]): {
    creators: Record<string, TikTokCreatorState>;
    reload: (accountId: string) => void;
} {
    const [creators, setCreators] = useState<
        Record<string, TikTokCreatorState>
    >({});

    const load = useCallback((accountId: string) => {
        setCreators((current) => ({
            ...current,
            [accountId]: { status: 'loading' },
        }));

        fetch(TikTokCreatorInfoController.url(accountId), {
            headers: { Accept: 'application/json' },
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error(String(res.status));
                }

                return res.json() as Promise<CreatorInfoResponse>;
            })
            .then((data) => {
                setCreators((current) => ({
                    ...current,
                    [accountId]: data.available
                        ? { status: 'available', creator: data.creator }
                        : { status: 'unavailable', message: data.message },
                }));
            })
            .catch(() => {
                setCreators((current) => ({
                    ...current,
                    [accountId]: UNREACHABLE,
                }));
            });
    }, []);

    const key = accountIds.join(',');

    useEffect(() => {
        for (const accountId of accountIds) {
            if (creators[accountId] === undefined) {
                load(accountId);
            }
        }
        // oxlint-disable-next-line react-hooks/exhaustive-deps -- keyed on the id list; `creators` is read only to skip accounts already requested
    }, [key, load]);

    return { creators, reload: load };
}
