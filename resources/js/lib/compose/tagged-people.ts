import type { MediaView } from '@/types/compose';

/** X rejects more than 10 tagged people on one post. */
export const MAX_TAGGED_PEOPLE = 10;

const HANDLE = /^@?(\w{1,15})$/;

/** A bare X handle from typed or pasted input, or null when it is not one. */
export function parseTaggedHandle(input: string): string | null {
    return HANDLE.exec(input.trim())?.[1] ?? null;
}

/** X only allows tagging people in photos, never video or a GIF. */
export function hasTaggablePhotos(media: MediaView[]): boolean {
    return media.some((m) => m.kind === 'image' && m.mime !== 'image/gif');
}
