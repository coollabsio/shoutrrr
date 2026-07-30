import { usePage } from '@inertiajs/react';
import { AlertTriangle, ImagePlay, Paperclip, Smile } from 'lucide-react';
import { useState, type RefObject } from 'react';

import ConversationGifController from '@/actions/App/Http/Controllers/Gifs/ConversationGifController';
import ConversationMediaController from '@/actions/App/Http/Controllers/Messaging/ConversationMediaController';
import ConversationVideoUploadController from '@/actions/App/Http/Controllers/Messaging/ConversationVideoUploadController';
import { EmojiPopover } from '@/components/compose/emoji-popover';
import { GifPopover } from '@/components/compose/gif-popover';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { Textarea } from '@/components/ui/textarea';
import { useAttachments } from '@/hooks/compose/use-attachments';
import { useEmojiPreferences } from '@/hooks/compose/use-emoji-preferences';
import { cn } from '@/lib/utils';
import type { MediaView } from '@/types/compose';

import type { PlatformName } from '../types';

export const QUICK_MESSAGE_SEND_SHORTCUT = '⌘/Ctrl↵';

const DEFAULT_MAX_LENGTH = 1000;

/** Mirrors `Platform::supportsDirectMessageMedia()` — Bluesky DMs take none. */
const DM_MEDIA_PLATFORMS: PlatformName[] = ['x', 'instagram', 'facebook'];

/** Mirrors `Platform::maxDirectMessageMedia()`. */
const MAX_MEDIA = 1;

type Props = {
    conversationId: string;
    platform: PlatformName;
    canReply: boolean;
    replyingTo?: string;
    maxLength?: number;
    editorRef?: RefObject<HTMLTextAreaElement | null>;
    /** Whole `MediaView`s, not ids, so the optimistic bubble can show the image. */
    onSend: (text: string, media: MediaView[]) => Promise<void>;
};

export function QuickMessageBox({
    conversationId,
    platform,
    canReply,
    replyingTo,
    maxLength = DEFAULT_MAX_LENGTH,
    editorRef,
    onSend,
}: Props) {
    const { shell } = usePage().props;
    const [text, setText] = useState('');
    const [media, setMedia] = useState<MediaView[]>([]);
    const [sending, setSending] = useState(false);
    const emojiPrefs = useEmojiPreferences();

    const canAttach = DM_MEDIA_PLATFORMS.includes(platform);

    // No `imageEdit` endpoints: a DM skips the crop/beautify editor.
    const attachments = useAttachments({
        ownerId: conversationId,
        platform,
        media,
        onChange: setMedia,
        subject: 'message',
        endpoints: {
            imageStore: (id) => ConversationMediaController.store(id).url,
            videoSign: (id) => ConversationVideoUploadController.url(id).url,
            videoStore: (id) => ConversationVideoUploadController.store(id).url,
            gifStore: (id) =>
                ConversationGifController.store.url({ conversation: id }),
        },
    });

    const remaining = maxLength - text.length;
    const tooLong = remaining < 0;
    const empty = text.trim() === '' && media.length === 0;
    const disabled = !canReply;
    // A half-uploaded attachment has no id yet, so hold the send.
    const canSend =
        !disabled && !empty && !tooLong && !sending && !attachments.isUploading;
    const mediaFull = media.length >= MAX_MEDIA;
    const showAttachControls = canAttach && !disabled && !mediaFull;

    function insertEmoji(emoji: string) {
        emojiPrefs.addRecent(emoji);

        const field = editorRef?.current;
        if (!field) {
            setText((prev) => prev + emoji);

            return;
        }

        const start = field.selectionStart;
        const end = field.selectionEnd;
        setText((prev) => prev.slice(0, start) + emoji + prev.slice(end));

        // Restore the caret after React commits, or it snaps to the end.
        requestAnimationFrame(() => {
            field.focus();
            field.setSelectionRange(start + emoji.length, start + emoji.length);
        });
    }

    async function send() {
        if (!canSend) {
            return;
        }
        setSending(true);
        try {
            await onSend(text, media);
            setText('');
            setMedia([]);
        } finally {
            setSending(false);
        }
    }

    return (
        <div
            className="shrink-0 border-t bg-background p-3"
            {...(canAttach ? attachments.dropHandlers : {})}
        >
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
                onPaste={(e) => {
                    if (
                        canAttach &&
                        !mediaFull &&
                        e.clipboardData.files.length > 0
                    ) {
                        e.preventDefault();
                        void attachments.handleAddedFiles(
                            e.clipboardData.files,
                        );
                    }
                }}
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

            {canAttach ? attachments.fileInput : null}
            {canAttach && attachments.chips ? (
                <div className="mt-2">{attachments.chips}</div>
            ) : null}

            <div className="mt-2 flex items-center gap-2">
                <EmojiPopover
                    recents={emojiPrefs.recents}
                    skinTone={emojiPrefs.skinTone}
                    onSkinToneChange={emojiPrefs.setSkinTone}
                    onSelect={insertEmoji}
                    side="top"
                    align="start"
                    tooltip="Emoji"
                    trigger={(open) => (
                        <button
                            type="button"
                            aria-label="Insert emoji"
                            disabled={disabled || sending}
                            data-active={open}
                            className={cn(
                                'inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors',
                                'hover:bg-accent hover:text-foreground',
                                'data-[active=true]:bg-accent data-[active=true]:text-foreground',
                                'disabled:pointer-events-none disabled:opacity-50',
                            )}
                        />
                    )}
                >
                    <Smile className="size-4" aria-hidden="true" />
                </EmojiPopover>

                {showAttachControls ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Attach photo or video"
                        title="Attach photo or video"
                        disabled={sending}
                        onClick={attachments.openFilePicker}
                        className="size-8 shrink-0 text-muted-foreground hover:text-foreground"
                    >
                        <Paperclip className="size-4" aria-hidden="true" />
                    </Button>
                ) : null}

                {showAttachControls && shell.gifs_enabled ? (
                    <GifPopover
                        onSelect={(item) => void attachments.attachGif(item)}
                        side="top"
                        align="start"
                        tooltip="GIFs, stickers & clips"
                        trigger={(open) => (
                            <button
                                type="button"
                                aria-label="Insert a GIF, sticker or clip"
                                disabled={sending}
                                data-active={open}
                                className={cn(
                                    'inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors',
                                    'hover:bg-accent hover:text-foreground',
                                    'data-[active=true]:bg-accent data-[active=true]:text-foreground',
                                    'disabled:pointer-events-none disabled:opacity-50',
                                )}
                            />
                        )}
                    >
                        <ImagePlay className="size-4" aria-hidden="true" />
                    </GifPopover>
                ) : null}

                {canAttach && attachments.isUploading ? (
                    <span className="text-[11px] text-muted-foreground">
                        Uploading…
                    </span>
                ) : null}

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
