/**
 * dataTransfer payload helpers for dragging an attached media chip between
 * per-segment media rows. Namespaced so the payload doesn't collide with a
 * browser/file drag (which carries `Files`/`text/uri-list` instead).
 */
const MEDIA_DRAG_MIME = 'application/x-composer-media';

/** Structural subset of native/React drag events — easy to fake in tests. */
type MediaDragEvent = {
    dataTransfer: {
        setData: (format: string, data: string) => void;
        getData: (format: string) => string;
    } | null;
};

/** Tag a dragstart with the media id being dragged. */
export function setMediaDrag(e: MediaDragEvent, mediaId: string): void {
    e.dataTransfer?.setData(MEDIA_DRAG_MIME, mediaId);
}

/** Read the dragged media id off a drop event, or null if this drag isn't ours. */
export function getMediaDrag(e: MediaDragEvent): string | null {
    const id = e.dataTransfer?.getData(MEDIA_DRAG_MIME);

    return id ? id : null;
}
