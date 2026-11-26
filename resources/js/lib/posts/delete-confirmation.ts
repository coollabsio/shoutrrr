import { sentToTikTokInbox } from '@/lib/compose/tiktok';
import { platformLabel } from '@/lib/platforms';
import type { PlatformName, PostStatus, TargetView } from '@/types/compose';

/**
 * Platforms whose API has no way to delete a published post, mirroring
 * `Platform::supportsRemoteDelete()` on the server.
 */
const PLATFORMS_WITHOUT_REMOTE_DELETE: ReadonlySet<PlatformName> = new Set([
    'tiktok',
]);

type DeletableTarget = Pick<TargetView, 'platform' | 'status'> &
    Partial<Pick<TargetView, 'tiktok'>>;

type DeletablePost = {
    status: PostStatus;
    targets?: DeletableTarget[];
};

const TIKTOK_INBOX_NOTE =
    'The draft sent to the TikTok inbox is not removed: delete it in the TikTok app, and the post too if it was published from there.';

/**
 * The body of the "Delete post?" confirmation. Published copies on a platform
 * that can't delete through its API stay live, so the dialog says so up front
 * rather than leaving the user to find out from the flash afterwards. A draft
 * sent to a TikTok inbox is not live, so it gets its own note.
 */
export function deleteConfirmationDescription(post: DeletablePost): string {
    if (post.status === 'draft' || post.status === 'scheduled') {
        return 'This removes the post. The content is not kept.';
    }

    const leftBehind = (post.targets ?? []).filter(
        (target) =>
            target.status === 'published' &&
            PLATFORMS_WITHOUT_REMOTE_DELETE.has(target.platform),
    );
    const stayLive = [
        ...new Set(
            leftBehind
                .filter((target) => !sentToTikTokInbox(target))
                .map((target) => platformLabel(target.platform)),
        ),
    ];
    const notes = [
        'Published copies will be removed from connected accounts where possible.',
    ];

    if (stayLive.length > 0) {
        notes.push(
            `It stays live on ${stayLive.join(', ')}, which can't delete posts through its API — delete it there directly.`,
        );
    }

    if (leftBehind.some(sentToTikTokInbox)) {
        notes.push(TIKTOK_INBOX_NOTE);
    }

    return notes.join(' ');
}
