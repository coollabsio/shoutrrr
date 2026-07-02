import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import PostVideoUploadController from '@/actions/App/Http/Controllers/Posts/PostVideoUploadController';
import {
    minVideoBytes,
    putWithProgress,
    readVideoMetadata,
    validateVideo,
} from '@/lib/compose/video';
import type { VideoEditSettings } from '@/lib/video-editor/settings';
import type { MediaView, PlatformLimits } from '@/types/compose';

type ApplyInput = {
    source: Blob;
    oldMediaId: string | null;
    settings: VideoEditSettings;
    altText?: string;
    limits: PlatformLimits[];
};

type Args = {
    onEnsurePost: () => Promise<string>;
    onComplete: (oldMediaId: string | null, media: MediaView) => void;
};

export function useVideoEditor({ onEnsurePost, onComplete }: Args) {
    const [phase, setPhase] = useState<
        'idle' | 'rendering' | 'compressing' | 'uploading'
    >('idle');
    const [progress, setProgress] = useState(0);

    const signHttp = useHttp<
        { content_type: string },
        { key: string; url: string; headers: Record<string, string> }
    >({ content_type: 'video/mp4' });

    const confirmHttp = useHttp<
        {
            key: string;
            duration_seconds: number;
            width: number;
            height: number;
            alt_text: string | null;
        },
        { media: MediaView }
    >({ key: '', duration_seconds: 0, width: 0, height: 0, alt_text: null });

    async function apply({
        source,
        oldMediaId,
        settings,
        altText = '',
        limits,
    }: ApplyInput): Promise<boolean> {
        try {
            setPhase('rendering');
            setProgress(0);
            const { renderVideo } = await import('@/lib/video-editor/render');
            const blob = await renderVideo(source, settings, setProgress);

            let file = new File([blob], 'edited-video.mp4', {
                type: 'video/mp4',
            });
            let meta = await readVideoMetadata(file);

            // Keep the edited output within the selected platforms' caps too.
            const maxBytes = minVideoBytes(limits);
            if (Number.isFinite(maxBytes) && file.size > maxBytes) {
                setPhase('compressing');
                setProgress(0);
                const { compressVideoToFit } =
                    await import('@/lib/video-editor/compress');
                const compressed = await compressVideoToFit(
                    file,
                    maxBytes,
                    setProgress,
                );
                if (compressed) {
                    file = new File([compressed], 'edited-video.mp4', {
                        type: 'video/mp4',
                    });
                    meta = await readVideoMetadata(file);
                }
            }

            const verdict = validateVideo(meta, limits);
            if (!verdict.ok) {
                toast.error(verdict.reason);

                return false;
            }

            setPhase('uploading');
            setProgress(0);
            const id = await onEnsurePost();

            // 1. Sign → 2. PUT direct to storage → 3. confirm.
            signHttp.setData({ content_type: 'video/mp4' });
            const signed = await signHttp.post(
                PostVideoUploadController.url(id).url,
                {
                    onNetworkError: () => undefined,
                },
            );

            await putWithProgress(signed.url, signed.headers, file, (pct) =>
                setProgress(pct / 100),
            );

            confirmHttp.setData({
                key: signed.key,
                duration_seconds: meta.durationSeconds,
                width: meta.width,
                height: meta.height,
                alt_text: altText || null,
            });
            const { media } = await confirmHttp.post(
                PostVideoUploadController.store(id).url,
                {
                    onNetworkError: () => undefined,
                },
            );

            onComplete(oldMediaId, media);

            return true;
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Could not save the edited video.',
            );

            return false;
        } finally {
            setPhase('idle');
        }
    }

    return { apply, phase, progress };
}
