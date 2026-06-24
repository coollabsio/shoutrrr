import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import PostImageEditController from '@/actions/App/Http/Controllers/Posts/PostImageEditController';
import type { EditSettings } from '@/lib/image-editor/settings';
import type { MediaView } from '@/types/compose';

type Options = {
    onEnsurePost: () => Promise<string>;
    onAddMedia: (media: MediaView) => void;
    onReplaceMedia: (media: MediaView) => void;
};

function blobToFile(blob: Blob, name: string): File {
    return new File([blob], name, { type: blob.type || 'image/png' });
}

export function useImageEditor({
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
                composed: blobToFile(composed, 'image.png'),
                source: blobToFile(source, 'source.png'),
                settings: JSON.stringify(settings),
            }));
            const { media } = await http.post(
                PostImageEditController.store(id).url,
                { onNetworkError: () => undefined },
            );
            onAddMedia(media);
        } catch {
            toast.error('Could not save the image.');
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
                composed: blobToFile(composed, 'image.png'),
                settings: JSON.stringify(settings),
                _method: 'put',
            }));
            const { media } = await http.post(
                PostImageEditController.update({ post: id, media: mediaId })
                    .url,
                { onNetworkError: () => undefined },
            );
            onReplaceMedia(media);
        } catch {
            toast.error('Could not update the image.');
        } finally {
            setIsSaving(false);
        }
    }

    return { applyNew, applyEdit, isSaving };
}
