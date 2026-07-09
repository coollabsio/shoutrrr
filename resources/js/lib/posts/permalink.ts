import { platformLabel } from '@/lib/platforms';
import type { PlatformName } from '@/types/compose';

function cleanHandle(handle: string): string {
    return handle.replace(/^@/, '').trim();
}

/**
 * Public URL of a published post on its platform, built from the stored remote
 * id and the account handle. Returns null when a URL can't be constructed
 * (missing remote id/handle, or an unrecognised id format).
 */
export function postPermalink(
    platform: PlatformName,
    handle: string | null | undefined,
    remoteId: string | null | undefined,
): string | null {
    if (!remoteId) {
        return null;
    }
    const h = handle ? cleanHandle(handle) : '';

    switch (platform) {
        case 'bluesky': {
            // remote_id is an AT-URI (at://did/app.bsky.feed.post/<rkey>).
            const rkey = remoteId.split('/').pop() ?? '';
            return h && rkey
                ? `https://bsky.app/profile/${h}/post/${rkey}`
                : null;
        }
        case 'x':
            return h ? `https://x.com/${h}/status/${remoteId}` : null;
        case 'linkedin':
            return remoteId.startsWith('urn:li:')
                ? `https://www.linkedin.com/feed/update/${remoteId}/`
                : null;
        default:
            return null;
    }
}

export { platformLabel };
