import { useHttp, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import ReplyImageEditController from '@/actions/App/Http/Controllers/Engagement/ReplyImageEditController';
import ReplyMediaController from '@/actions/App/Http/Controllers/Engagement/ReplyMediaController';
import ReplyVideoUploadController from '@/actions/App/Http/Controllers/Engagement/ReplyVideoUploadController';
import { ImageEditor } from '@/components/compose/image-editor';
import { MediaChips } from '@/components/compose/media-chips';
import { useMediaUploads } from '@/hooks/compose/use-media-uploads';
import {
    defaultSettings,
    type EditSettings,
    normalizeSettings,
} from '@/lib/image-editor/settings';
import type { MediaView, PlatformName } from '@/types/compose';

/** What the image editor is currently working on. */
type Editing =
    | {
          kind: 'batch';
          items: { file: File; url: string }[];
          index: number;
      }
    | {
          kind: 'reedit';
          url: string;
          settings: EditSettings;
          mediaId: string;
          altText: string | null;
      }
    | { kind: 'raw'; url: string; mediaId: string };

/** Stable fallback so a closed editor doesn't reallocate settings each render. */
const DEFAULT_EDIT_SETTINGS = defaultSettings();

type Args = {
    replyId: string;
    platform: PlatformName;
    media: MediaView[];
    onChange: (media: MediaView[]) => void;
};

/**
 * The render-ready pieces the reply box composes into its own layout: a hidden
 * file input, the attach trigger, the media-chips strip (null when empty), the
 * single image-editor instance, and the drag-drop handlers.
 */
type ReplyMedia = {
    isUploading: boolean;
    hasMedia: boolean;
    openFilePicker: () => void;
    fileInput: ReactNode;
    chips: ReactNode | null;
    editor: ReactNode;
    dropHandlers: {
        onDragOver: (e: React.DragEvent) => void;
        onDrop: (e: React.DragEvent) => void;
    };
};

function blobToFile(blob: Blob, name: string): File {
    return new File([blob], name, { type: blob.type || 'image/png' });
}

/**
 * Owns the reply box's media lifecycle — upload, the crop/beautify editor, and
 * the attached-media strip — and hands back render-ready pieces so the reply box
 * can lay out the attach button, chips, and footer however it likes. All upload
 * logic is reused from the composer's `useMediaUploads`, `MediaChips`, and
 * `ImageEditor`; only the upload endpoints are reply-scoped.
 */
export function useReplyMedia({
    replyId,
    platform,
    media,
    onChange,
}: Args): ReplyMedia {
    const { shell } = usePage().props;
    // Validate video against the reply's own platform limits, not every platform.
    const videoLimits = shell.limits.filter((l) => l.platform === platform);

    const fileInputRef = useRef<HTMLInputElement | null>(null);

    // --- Image-edit state machine (mirrors composer.tsx) -------------------

    const [editing, setEditing] = useState<Editing | null>(null);
    const editingRef = useRef<Editing | null>(null);
    editingRef.current = editing;

    // Revoke batch object URLs on unmount.
    useEffect(
        () => () => {
            const e = editingRef.current;
            if (e?.kind === 'batch') {
                for (const it of e.items) {
                    URL.revokeObjectURL(it.url);
                }
            }
        },
        [],
    );

    // --- useMediaUploads ---------------------------------------------------

    const { pending, isUploading, handleFiles, dismissPending } =
        useMediaUploads({
            media,
            videoLimits,
            onEnsurePost: async () => replyId,
            onAddMedia: (m) => onChange([...media, m]),
            endpoints: {
                imageStore: (id) => ReplyMediaController.store(id).url,
                videoSign: (id) => ReplyVideoUploadController.url(id).url,
                videoStore: (id) => ReplyVideoUploadController.store(id).url,
            },
        });

    // --- Image-editor HTTP (inline, mirrors use-image-editor.ts) ----------

    const editHttp = useHttp<{ composed?: File | null }, { media: MediaView }>(
        {},
    );
    const [isSaving, setIsSaving] = useState(false);

    async function applyEditing(
        composed: Blob,
        settings: EditSettings,
        altText: string,
    ): Promise<void> {
        if (!editing) {
            return;
        }
        setIsSaving(true);
        try {
            if (editing.kind === 'batch') {
                editHttp.transform(() => ({
                    composed: blobToFile(composed, 'image.png'),
                    source: blobToFile(
                        editing.items[editing.index].file,
                        'source.png',
                    ),
                    settings: JSON.stringify(settings),
                    alt_text: altText,
                }));
                const { media: result } = await editHttp.post(
                    ReplyImageEditController.store(replyId).url,
                    { onNetworkError: () => undefined },
                );
                onChange([...media, result]);
            } else if (editing.kind === 'reedit') {
                editHttp.transform(() => ({
                    composed: blobToFile(composed, 'image.png'),
                    settings: JSON.stringify(settings),
                    alt_text: altText,
                    _method: 'put',
                }));
                const { media: result } = await editHttp.post(
                    ReplyImageEditController.update({
                        reply: replyId,
                        media: editing.mediaId,
                    }).url,
                    { onNetworkError: () => undefined },
                );
                onChange(
                    media.map((m) => (m.id === editing.mediaId ? result : m)),
                );
            } else {
                // 'raw': beautify a plain attachment for the first time —
                // fetch the original blob, upload as new, drop the raw attachment.
                const rawBlob = await fetch(editing.url).then((r) => r.blob());
                editHttp.transform(() => ({
                    composed: blobToFile(composed, 'image.png'),
                    source: blobToFile(rawBlob, 'source.png'),
                    settings: JSON.stringify(settings),
                    alt_text: altText,
                }));
                const { media: result } = await editHttp.post(
                    ReplyImageEditController.store(replyId).url,
                    { onNetworkError: () => undefined },
                );
                onChange([
                    ...media.filter((m) => m.id !== editing.mediaId),
                    result,
                ]);
            }
        } catch {
            toast.error('Could not save the image.');
            setIsSaving(false);

            return;
        }
        setIsSaving(false);
        endEditingStep();
    }

    function endEditingStep() {
        if (editing?.kind === 'batch') {
            if (editing.index + 1 < editing.items.length) {
                setEditing({ ...editing, index: editing.index + 1 });

                return;
            }
            for (const it of editing.items) {
                URL.revokeObjectURL(it.url);
            }
        }
        setEditing(null);
    }

    function cancelEditing() {
        if (editing?.kind === 'batch') {
            void handleFiles([editing.items[editing.index].file]);
        }
        endEditingStep();
    }

    function discardEditing() {
        if (editing?.kind === 'reedit' || editing?.kind === 'raw') {
            onChange(media.filter((m) => m.id !== editing.mediaId));
        }
        endEditingStep();
    }

    // --- File handling (mirrors handleAddedFiles in composer.tsx) ----------

    async function handleAddedFiles(files: FileList | File[]): Promise<void> {
        const all = Array.from(files);
        const videos = all.filter((f) => f.type.startsWith('video/'));
        const images = all.filter((f) => !f.type.startsWith('video/'));

        const hasVideoNow = media.some((m) => m.kind === 'video');
        const hasImageNow = media.some((m) => m.kind !== 'video');
        if (
            (videos.length > 0 && (images.length > 0 || hasImageNow)) ||
            (images.length > 0 && hasVideoNow)
        ) {
            toast.error('A reply can contain one video or images, not both.');

            return;
        }

        if (videos.length > 0) {
            void handleFiles(videos);

            return;
        }
        if (images.length === 0) {
            return;
        }
        setEditing({
            kind: 'batch',
            items: images.map((f) => ({
                file: f,
                url: URL.createObjectURL(f),
            })),
            index: 0,
        });
    }

    function openEditor(mediaId: string) {
        const m = media.find((x) => x.id === mediaId);
        if (!m || m.kind === 'video') {
            return;
        }
        if (m.edit_settings && m.source_url) {
            setEditing({
                kind: 'reedit',
                url: m.source_url,
                settings: normalizeSettings(m.edit_settings),
                mediaId: m.id,
                altText: m.alt_text,
            });
        } else {
            setEditing({ kind: 'raw', url: m.url, mediaId: m.id });
        }
    }

    // --- Derived editor props ---------------------------------------------

    const editorSourceUrl =
        editing?.kind === 'batch'
            ? editing.items[editing.index].url
            : (editing?.url ?? null);
    const editorSettings =
        editing?.kind === 'reedit' ? editing.settings : DEFAULT_EDIT_SETTINGS;
    const editorAltText = editing?.kind === 'reedit' ? editing.altText : null;
    const editorQueue =
        editing?.kind === 'batch'
            ? {
                  thumbnails: editing.items.map((it) => it.url),
                  index: editing.index,
              }
            : undefined;

    const hasVideo = media.some((m) => m.kind === 'video');
    const hasMedia = media.length > 0 || pending.length > 0;

    function acceptFromInput(files: FileList) {
        void handleAddedFiles(files).finally(() => {
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        });
    }

    const fileInput = (
        <input
            ref={fileInputRef}
            type="file"
            accept={hasVideo ? 'image/*' : 'image/*,video/*'}
            multiple
            hidden
            onChange={(e) => {
                if (e.target.files && e.target.files.length > 0) {
                    acceptFromInput(e.target.files);
                }
            }}
        />
    );

    const chips = hasMedia ? (
        <MediaChips
            media={media}
            pending={pending}
            isExcluded={() => false}
            onToggleExclude={() => {}}
            onReorder={(ids) =>
                onChange(
                    ids
                        .map((id) => media.find((m) => m.id === id)!)
                        .filter(Boolean),
                )
            }
            onRemove={(id) => onChange(media.filter((m) => m.id !== id))}
            onDismissPending={dismissPending}
            onImageClick={openEditor}
        />
    ) : null;

    const editor = (
        <ImageEditor
            open={editing !== null}
            sourceUrl={editorSourceUrl}
            initialSettings={editorSettings}
            initialAltText={editorAltText}
            onApply={applyEditing}
            onCancel={cancelEditing}
            onDiscard={discardEditing}
            variant={editing?.kind === 'batch' ? 'new' : 'existing'}
            isSaving={isSaving}
            queue={editorQueue}
        />
    );

    return {
        isUploading,
        hasMedia,
        openFilePicker: () => fileInputRef.current?.click(),
        fileInput,
        chips,
        editor,
        dropHandlers: {
            onDragOver: (e) => e.preventDefault(),
            onDrop: (e) => {
                e.preventDefault();
                if (e.dataTransfer.files.length > 0) {
                    void handleAddedFiles(e.dataTransfer.files);
                }
            },
        },
    };
}
