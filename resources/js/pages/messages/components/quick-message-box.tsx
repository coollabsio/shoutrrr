import { AlertTriangle } from 'lucide-react';
import { useState, type RefObject } from 'react';

import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

export const QUICK_MESSAGE_SEND_SHORTCUT = '⌘/Ctrl↵';

const DEFAULT_MAX_LENGTH = 1000;

type Props = {
    canReply: boolean;
    replyingTo?: string;
    maxLength?: number;
    editorRef?: RefObject<HTMLTextAreaElement | null>;
    onSend: (text: string) => Promise<void>;
};

export function QuickMessageBox({
    canReply,
    replyingTo,
    maxLength = DEFAULT_MAX_LENGTH,
    editorRef,
    onSend,
}: Props) {
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);

    const remaining = maxLength - text.length;
    const tooLong = remaining < 0;
    const empty = text.trim() === '';
    const disabled = !canReply;
    const canSend = !disabled && !empty && !tooLong && !sending;

    async function send() {
        if (!canSend) {
            return;
        }
        setSending(true);
        try {
            await onSend(text);
            setText('');
        } finally {
            setSending(false);
        }
    }

    return (
        <div className="shrink-0 border-t bg-background p-3">
            {disabled ? (
                <div className="mb-2 flex items-start gap-2 rounded-lg border border-amber-500/20 bg-amber-500/10 px-3 py-2 text-xs text-amber-900 dark:text-amber-200">
                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                    <p>
                        Reply window closed — you can only reply within 24h on
                        Instagram/Facebook.
                    </p>
                </div>
            ) : null}

            <Textarea
                ref={editorRef}
                value={text}
                onChange={(e) => setText(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Escape') {
                        e.currentTarget.blur();
                        return;
                    }
                    if (
                        e.key === 'Enter' &&
                        (e.metaKey || e.ctrlKey) &&
                        !e.shiftKey
                    ) {
                        e.preventDefault();
                        void send();
                    }
                }}
                disabled={disabled || sending}
                placeholder={
                    replyingTo ? `Message ${replyingTo}…` : 'Write a message…'
                }
                className="min-h-16"
            />

            <div className="mt-2 flex items-center gap-2">
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
                    {sending ? (
                        'Sending…'
                    ) : (
                        <>
                            <span>Reply</span>
                            <Kbd
                                aria-hidden="true"
                                className="ml-0.5 hidden h-4 min-w-0 border border-primary-foreground/25 bg-primary-foreground/15 px-1 font-mono text-[10px] leading-none font-normal text-primary-foreground/90 sm:inline-flex"
                            >
                                {QUICK_MESSAGE_SEND_SHORTCUT}
                            </Kbd>
                        </>
                    )}
                </Button>
            </div>
        </div>
    );
}
