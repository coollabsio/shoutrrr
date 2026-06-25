import { CornerDownLeft } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

const LIMITS: Record<string, number> = { x: 280, bluesky: 300, linkedin: 3000 };

type Props = {
    platform: string;
    replyingTo?: string;
    maxLength?: number;
    disabled?: boolean;
    onSend: (text: string) => Promise<void>;
};

export function QuickReplyBox({
    platform,
    replyingTo,
    maxLength,
    disabled,
    onSend,
}: Props) {
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const limit = maxLength ?? LIMITS[platform] ?? 280;
    const remaining = limit - text.length;
    const tooLong = remaining < 0;
    const empty = text.trim() === '';

    async function send() {
        if (empty || tooLong || sending) {
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
        <div className="border-t bg-background/60 p-3">
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
            <div className="mt-2 flex items-center justify-between gap-3">
                <span className="flex items-center gap-1 text-[11px] text-muted-foreground">
                    <CornerDownLeft className="size-3" />
                    <kbd className="font-sans">⌘↵</kbd> to send
                </span>
                <div className="flex items-center gap-2.5">
                    <span
                        className={cn(
                            'text-xs tabular-nums',
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
                        disabled={disabled || sending || tooLong || empty}
                    >
                        {sending ? 'Sending…' : 'Reply'}
                    </Button>
                </div>
            </div>
        </div>
    );
}
