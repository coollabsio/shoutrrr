import { useHttp } from '@inertiajs/react';
import { Paperclip, Smile } from 'lucide-react';
import { useEffect, useRef, useState, type RefObject } from 'react';

import WorkspaceMentionController from '@/actions/App/Http/Controllers/WorkspaceMentionController';
import EditorBody, {
    type EditorBodyHandle,
} from '@/components/compose/editor-body';
import { EmojiPopover } from '@/components/compose/emoji-popover';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useEmojiPreferences } from '@/hooks/compose/use-emoji-preferences';
import {
    replaceMentionLabel,
    replaceMentionTokens,
    savedMentionToPlaceholder,
    syncMentionsFromText,
    type MentionPlaceholder,
} from '@/lib/compose/mentions';
import { cn } from '@/lib/utils';
import type {
    MediaView,
    PlatformName,
    WorkspaceMention,
} from '@/types/compose';

import { useReplyMedia } from './use-reply-media';

const EMPTY_SAVED_MENTIONS: WorkspaceMention[] = [];

const LIMITS: Record<string, number> = { x: 280, bluesky: 300, linkedin: 3000 };
export const QUICK_REPLY_SEND_SHORTCUT = '⌘/Ctrl↵';

type Props = {
    replyId: string;
    platform: PlatformName;
    replyingTo?: string;
    maxLength?: number;
    disabled?: boolean;
    disabledReason?: string;
    editorRef?: RefObject<EditorBodyHandle | null>;
    /** Workspace saved-mention library, shared with the composer's picker. */
    savedMentions?: WorkspaceMention[];
    onSend: (text: string, mediaIds: string[]) => Promise<void>;
};

export function QuickReplyBox({
    replyId,
    platform,
    replyingTo,
    maxLength,
    disabled,
    disabledReason,
    editorRef: editorRefProp,
    savedMentions: initialSavedMentions = EMPTY_SAVED_MENTIONS,
    onSend,
}: Props) {
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [media, setMedia] = useState<MediaView[]>([]);
    const [mentions, setMentions] = useState<MentionPlaceholder[]>([]);
    const [savedMentions, setSavedMentions] = useState(initialSavedMentions);
    useEffect(() => {
        setSavedMentions(initialSavedMentions);
    }, [initialSavedMentions]);
    const saveMentionHttp = useHttp<
        Record<string, never>,
        { mention: WorkspaceMention }
    >({});

    const rm = useReplyMedia({ replyId, platform, media, onChange: setMedia });

    // The parent drives focus (the "r" triage shortcut) through this handle; when
    // it doesn't pass one we fall back to a local ref so emoji insertion still
    // works.
    const fallbackEditorRef = useRef<EditorBodyHandle>(null);
    const editorRef = editorRefProp ?? fallbackEditorRef;
    const emojiPrefs = useEmojiPreferences();

    function insertEmoji(emoji: string) {
        editorRef.current?.insertText(emoji);
        emojiPrefs.addRecent(emoji);
    }

    // Keep the mention list reconciled with the text as the user types, mirroring
    // the composer's syncMentions — minus the segment/override machinery, since a
    // reply is a single plain string.
    function handleText(next: string) {
        setText(next);
        setMentions((current) =>
            syncMentionsFromText(next, current, savedMentions),
        );
    }

    // Rename a mention (via the picker) in both the text and the mention list.
    // Setting the text re-drives the editor through EditorBody's external-sync
    // effect, so no separate editor mutation is needed.
    function renameMention(
        mention: MentionPlaceholder,
        next: MentionPlaceholder,
    ) {
        setText((current) =>
            replaceMentionLabel(current, mention.label, next.label),
        );
        setMentions((current) =>
            current.map((item) => (item.id === mention.id ? next : item)),
        );
    }

    async function saveMention(mention: MentionPlaceholder): Promise<void> {
        saveMentionHttp.transform(() => ({
            name: mention.label,
            handles: mention.handles,
        }));
        const response = await saveMentionHttp.post(
            WorkspaceMentionController.store().url,
        );
        setSavedMentions((current) => {
            const others = current.filter(
                (item) =>
                    item.id !== response.mention.id &&
                    item.name !== response.mention.name,
            );

            return [...others, response.mention].sort((left, right) =>
                left.name.localeCompare(right.name),
            );
        });
    }

    // What actually gets sent: mention labels resolved to this platform's handle.
    // Char counting also uses the resolved text so the count matches the payload.
    const outgoing = replaceMentionTokens(text, mentions, platform);
    const limit = maxLength ?? LIMITS[platform] ?? 280;
    const remaining = limit - outgoing.length;
    const tooLong = remaining < 0;
    const empty = outgoing.trim() === '' && media.length === 0;
    const canSend = !empty && !tooLong && !sending && !rm.isUploading;

    async function send() {
        if (!canSend) {
            return;
        }
        setSending(true);
        try {
            await onSend(
                outgoing,
                media.map((m) => m.id),
            );
            setText('');
            setMedia([]);
            setMentions([]);
        } finally {
            setSending(false);
        }
    }

    return (
        <div
            className="shrink-0 border-t bg-background p-3"
            {...rm.dropHandlers}
        >
            {rm.fileInput}

            {/* The editor is styling-dumb, so the box owns the border/ring the
                <Textarea> used to bring with it. */}
            <div
                className={cn(
                    'rounded-xl border border-input bg-transparent shadow-xs transition-[color,box-shadow]',
                    'focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
                    (disabled || sending) && 'cursor-not-allowed opacity-50',
                )}
                onKeyDown={(e) => {
                    // Escape releases the editor so the j/k/r/a triage shortcuts
                    // work again without a stray keystroke landing in the reply.
                    if (e.key === 'Escape') {
                        editorRef.current?.blur();
                    }
                }}
            >
                <EditorBody
                    ref={editorRef}
                    compact
                    // Compact mode drops SectionBreak, so the doc can never hold a
                    // section break and docToSegments always returns exactly one
                    // segment — hence the plain-string adapter.
                    value={[text]}
                    onChange={(segments) => handleText(segments[0] ?? '')}
                    editable={!disabled && !sending}
                    onSubmit={() => void send()}
                    onPasteFiles={rm.handleAddedFiles}
                    emojiSkinTone={emojiPrefs.skinTone}
                    onEmojiInsert={emojiPrefs.addRecent}
                    // The composer's @-mention picker, scoped to this reply's one
                    // platform. Resolution happens client-side on send (outgoing).
                    mentions={mentions}
                    mentionPlatforms={[platform]}
                    savedMentions={savedMentions}
                    onMentionsChange={setMentions}
                    onMentionNameChange={renameMention}
                    onApplySavedMention={(mention, saved) =>
                        renameMention(mention, savedMentionToPlaceholder(saved))
                    }
                    onSaveMention={saveMention}
                    saveMentionProcessing={saveMentionHttp.processing}
                    placeholder={
                        replyingTo
                            ? `Reply to ${replyingTo}…`
                            : 'Write a reply…'
                    }
                />
            </div>

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

                <EmojiPopover
                    recents={emojiPrefs.recents}
                    skinTone={emojiPrefs.skinTone}
                    onSkinToneChange={emojiPrefs.setSkinTone}
                    onSelect={insertEmoji}
                    side="top"
                    align="start"
                    trigger={(open) => (
                        <button
                            type="button"
                            aria-label="Insert emoji"
                            title="Insert emoji"
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

                {rm.isUploading ? (
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

                {(() => {
                    const replyButton = (
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
                                        {QUICK_REPLY_SEND_SHORTCUT}
                                    </Kbd>
                                </>
                            )}
                        </Button>
                    );

                    // A disabled <button> swallows pointer events, so the tooltip
                    // trigger wraps a focusable span rather than the button itself.
                    return disabled && disabledReason ? (
                        <Tooltip>
                            <TooltipTrigger render={<span tabIndex={0} />}>
                                {replyButton}
                            </TooltipTrigger>
                            <TooltipContent side="top" align="end">
                                {disabledReason}
                            </TooltipContent>
                        </Tooltip>
                    ) : (
                        replyButton
                    );
                })()}
            </div>

            {rm.editor}
        </div>
    );
}
