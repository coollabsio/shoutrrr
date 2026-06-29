import { CornerDownLeft, Paperclip, Sparkles } from 'lucide-react';
import { useState } from 'react';

import ReplyAssistantController from '@/actions/App/Http/Controllers/Ai/ReplyAssistantController';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useAiStream } from '@/hooks/use-ai-stream';
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
    aiEnabled?: boolean;
    postExcerpt?: string | null;
};

export function QuickReplyBox({
    replyId,
    platform,
    replyingTo,
    maxLength,
    disabled,
    onSend,
    aiEnabled,
    postExcerpt,
}: Props) {
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [media, setMedia] = useState<MediaView[]>([]);
    const [suggestion, setSuggestion] = useState('');
    const aiStream = useAiStream();
    const suggesting = aiStream.status === 'streaming';

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

    function suggestReply(tone: string) {
        setSuggestion('');
        void aiStream.run(
            ReplyAssistantController.suggest({ reply: replyId }).url,
            { tone, post_excerpt: postExcerpt ?? undefined, limit },
            {
                onDelta: (t) => setSuggestion((prev) => prev + t),
                onDone: () => {},
                onError: () => setSuggestion(''),
            },
        );
    }

    return (
        <div className="border-t bg-background/60 p-3" {...rm.dropHandlers}>
            {rm.fileInput}

            {aiEnabled ? (
                <div className="mb-2">
                    {suggestion ? (
                        <div className="rounded-xl border bg-muted/40 p-2.5">
                            <p className="whitespace-pre-wrap text-[13px] text-foreground">
                                {suggestion}
                            </p>
                            <div className="mt-2 flex gap-1.5">
                                <Button
                                    type="button"
                                    size="sm"
                                    disabled={suggesting}
                                    onClick={() => {
                                        aiStream.cancel();
                                        setText(suggestion);
                                        setSuggestion('');
                                    }}
                                >
                                    Use
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => {
                                        aiStream.cancel();
                                        setSuggestion('');
                                    }}
                                >
                                    Dismiss
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="flex items-center gap-1 text-[11px] text-muted-foreground">
                                <Sparkles className="size-3" /> Suggest:
                            </span>
                            {(['friendly', 'professional', 'brief'] as const).map((tone) => (
                                <button
                                    key={tone}
                                    type="button"
                                    disabled={suggesting}
                                    onClick={() => suggestReply(tone)}
                                    className="rounded-full border border-border bg-background px-2.5 py-0.5 text-[12px] capitalize text-foreground/70 transition-colors hover:border-primary/30 hover:bg-primary/5 hover:text-foreground disabled:opacity-50"
                                >
                                    {tone}
                                </button>
                            ))}
                            {suggesting ? (
                                <span className="text-[11px] text-muted-foreground">
                                    Generating…
                                </span>
                            ) : null}
                        </div>
                    )}
                </div>
            ) : null}

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
