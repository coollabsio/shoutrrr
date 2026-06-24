import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import PostScreenshotController from '@/actions/App/Http/Controllers/Posts/PostScreenshotController';
import type { EditSettings } from '@/lib/screenshot/settings';
import type { MediaView } from '@/types/compose';

type Options = {
    onEnsurePost: () => Promise<string>;
    onAddMedia: (media: MediaView) => void;
    onReplaceMedia: (media: MediaView) => void;
};

function blobToFile(blob: Blob, name: string): File {
    return new File([blob], name, { type: blob.type || 'image/png' });
}

export function useScreenshot({
    onEnsurePost,
    onAddMedia,
    onReplaceMedia,
}: Options) {
    const http = useHttp<{ composed?: File | null }, { media: MediaView }>({});
    const [isSaving, setIsSaving] = useState(false);

    async function applyNew(
        composed: Blob,
        source: Blob,
        settings: EditSettings,
    ): Promise<void> {
        setIsSaving(true);
        try {
            const id = await onEnsurePost();
            if (!id) {
                return;
            }
            http.transform(() => ({
                composed: blobToFile(composed, 'screenshot.png'),
                source: blobToFile(source, 'source.png'),
                settings: JSON.stringify(settings),
            }));
            const { media } = await http.post(
                PostScreenshotController.store(id).url,
                { onNetworkError: () => undefined },
            );
            onAddMedia(media);
        } catch {
            toast.error('Could not save the screenshot.');
        } finally {
            setIsSaving(false);
        }
    }

    async function applyEdit(
        mediaId: string,
        composed: Blob,
        settings: EditSettings,
    ): Promise<void> {
        setIsSaving(true);
        try {
            const id = await onEnsurePost();
            if (!id) {
                return;
            }
            http.transform(() => ({
                composed: blobToFile(composed, 'screenshot.png'),
                settings: JSON.stringify(settings),
                _method: 'put',
            }));
            const { media } = await http.post(
                PostScreenshotController.update({ post: id, media: mediaId })
                    .url,
                { onNetworkError: () => undefined },
            );
            onReplaceMedia(media);
        } catch {
            toast.error('Could not update the screenshot.');
        } finally {
            setIsSaving(false);
        }
    }

    return { applyNew, applyEdit, isSaving };
}
