import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

const LIMITS: Record<string, number> = { x: 280, bluesky: 300, linkedin: 3000 };

type Props = {
    platform: string;
    disabled?: boolean;
    onSend: (text: string) => Promise<void>;
};

export function QuickReplyBox({ platform, disabled, onSend }: Props) {
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const limit = LIMITS[platform] ?? 280;
    const remaining = limit - text.length;
    const tooLong = remaining < 0;

    async function send() {
        if (text.trim() === '' || tooLong || sending) {
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
        <div className="border-t p-3">
            <Textarea
                value={text}
                disabled={disabled || sending}
                onChange={(e) => setText(e.target.value)}
                onKeyDown={(e) => {
                    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                        void send();
                    }
                }}
                placeholder="Write a reply…"
                rows={3}
                className="min-h-0 rounded-xl"
            />
            <div className="mt-2 flex items-center justify-between">
                <span
                    className={`text-xs ${tooLong ? 'text-destructive' : 'text-muted-foreground'}`}
                >
                    {remaining}
                </span>
                <Button
                    type="button"
                    size="sm"
                    onClick={() => void send()}
                    disabled={
                        disabled || sending || tooLong || text.trim() === ''
                    }
                >
                    {sending ? 'Sending…' : 'Reply'}
                </Button>
            </div>
        </div>
    );
}
