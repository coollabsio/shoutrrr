import { CornerDownLeft, Paperclip } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { MediaView, PlatformName } from '@/types/compose';

import { useReplyMedia } from './use-reply-media';

const LIMITS: Record<string, number> = { x: 280, bluesky: 300, linkedin: 3000 };

type Props = {
    replyId: string;
    platform: PlatformName;
    replyingTo?: string;
    maxLength?: number;
    disabled?: boolean;
    onSend: (text: string, mediaIds: string[]) => Promise<void>;
};

export function QuickReplyBox({
    replyId,
    platform,
    replyingTo,
    maxLength,
    disabled,
    onSend,
}: Props) {
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [media, setMedia] = useState<MediaView[]>([]);

    const rm = useReplyMedia({ replyId, platform, media, onChange: setMedia });

    const limit = maxLength ?? LIMITS[platform] ?? 280;
    const remaining = limit - text.length;
    const tooLong = remaining < 0;
    const empty = text.trim() === '' && media.length === 0;
    const canSend = !empty && !tooLong && !sending && !rm.isUploading;

    async function send() {
        if (!canSend) {
            return;
        }
        setSending(true);
        try {
            await onSend(
                text,
                media.map((m) => m.id),
            );
            setText('');
            setMedia([]);
        } finally {
            setSending(false);
        }
    }

    return (
        <div className="border-t bg-background/60 p-3" {...rm.dropHandlers}>
            {rm.fileInput}

            <Textarea
                value={text}
                disabled={disabled || sending}
                onChange={(e) => setText(e.target.value)}
                onKeyDown={(e) => {
                    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                        void send();
                    }
                }}
                placeholder={
                    replyingTo ? `Reply to ${replyingTo}…` : 'Write a reply…'
                }
                rows={3}
                className="min-h-0 resize-none rounded-xl"
            />

            {rm.chips ? <div className="mt-2">{rm.chips}</div> : null}

            <div className="mt-2 flex items-center gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Attach photo or video"
                    title="Attach photo or video"
                    disabled={disabled || sending}
                    onClick={rm.openFilePicker}
                    className="size-8 shrink-0 text-muted-foreground hover:text-foreground"
                >
                    <Paperclip className="size-4" aria-hidden="true" />
                </Button>

                <span className="flex items-center gap-1 text-[11px] text-muted-foreground">
                    {rm.isUploading ? (
                        'Uploading…'
                    ) : (
                        <>
                            <CornerDownLeft className="size-3" aria-hidden />
                            <kbd className="font-sans tracking-tight">⌘↵</kbd>
                            <span className="hidden sm:inline">to send</span>
                        </>
                    )}
                </span>

                <span
                    className={cn(
                        'ml-auto text-xs tabular-nums',
                        tooLong
                            ? 'font-medium text-destructive'
                            : remaining <= 20
                              ? 'text-amber-600 dark:text-amber-500'
                              : 'text-muted-foreground',
                    )}
                >
                    {remaining}
                </span>

                <Button
                    type="button"
                    size="sm"
                    onClick={() => void send()}
                    disabled={disabled || !canSend}
                >
                    {sending ? 'Sending…' : 'Reply'}
                </Button>
            </div>

            {rm.editor}
        </div>
    );
}
