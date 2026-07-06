import { EditorContent, useEditor } from '@tiptap/react';
import { Split } from 'lucide-react';
import type { Ref } from 'react';
import {
    forwardRef,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
} from 'react';

import EmojiSuggestList from '@/components/compose/emoji-suggest-list';
import MentionPicker from '@/components/compose/mention-picker';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import { loadEmojiIndex, rankEmoji } from '@/lib/compose/emoji/shortcode-index';
import type { EmojiMatch, EmojiSkinTone } from '@/lib/compose/emoji/types';
import { mentionInputValue, updateMentionName } from '@/lib/compose/mentions';
import {
    docToSegments,
    segmentsToDoc,
    type DocNode,
} from '@/lib/compose/tiptap-doc';
import {
    emojiSuggestKey,
    type EmojiSuggestState,
} from '@/lib/compose/tiptap/emoji-suggest';
import {
    editorContainsMentionLabel,
    findMentionLabelStart,
    focusEditorAfterMentionLabel,
    removeMentionLabel,
} from '@/lib/compose/tiptap/mention-focus';
import { composerExtensions } from '@/lib/compose/tiptap/setup';
import { cn } from '@/lib/utils';
import type {
    MentionPlaceholder,
    PlatformName,
    WorkspaceMention,
} from '@/types/compose';

type EditorBodyProps = {
    value: string[];
    onChange: (segments: string[]) => void;
    onBlur: () => void;
    placeholder?: string;
    /** When false, the post is read-only (e.g. already published/scheduled). */
    editable?: boolean;
    /** When true, render the ring-tinted override banner above the editor. */
    overrideBanner?: boolean;
    /** Human label of the active platform for the override banner copy. */
    activePlatformLabel?: string | null;
    /** Reset-to-base handler for the override banner. */
    onResetOverride?: () => void;
    /** Focus the editor when it mounts. */
    autoFocus?: boolean;
    /**
     * Handle image/video files pasted (⌘/Ctrl+V) into the editor. Omit on a
     * read-only post to disable paste-to-upload.
     */
    onPasteFiles?: (files: FileList) => void;
    /**
     * Active platform + splitting config pushed into the section-markers plugin
     * whenever the active tab changes. Omit to leave markers at their defaults.
     */
    mentions?: MentionPlaceholder[];
    mentionPlatforms?: PlatformName[];
    savedMentions?: WorkspaceMention[];
    onMentionsChange?: (mentions: MentionPlaceholder[]) => void;
    onMentionNameChange?: (
        mention: MentionPlaceholder,
        next: MentionPlaceholder,
    ) => void;
    onApplySavedMention?: (
        mention: MentionPlaceholder,
        saved: WorkspaceMention,
    ) => void;
    onSaveMention?: (mention: MentionPlaceholder) => Promise<void>;
    saveMentionProcessing?: boolean;
    markerState?: {
        platform: PlatformName;
        autoSplit: boolean;
        limit: number;
        threadMax: number | null;
    };
    /** Active skin tone for typeahead results. */
    emojiSkinTone?: EmojiSkinTone;
    /** Record a chosen emoji as recently used. */
    onEmojiInsert?: (emoji: string) => void;
};

export type EditorBodyHandle = {
    /** Insert text (e.g. an emoji) at the current selection and refocus. */
    insertText: (text: string) => void;
};

export function shouldFocusEditorOnMount(
    autoFocus: boolean,
    editable: boolean,
): boolean {
    return autoFocus && editable;
}

/** A file we attach on paste/drop — images and videos only. */
export function isPasteableMediaFile(file: File): boolean {
    return file.type.startsWith('image/') || file.type.startsWith('video/');
}

/** True when a paste carries at least one image/video we should intercept. */
export function hasPasteableMedia(files: FileList | null | undefined): boolean {
    return !!files && Array.from(files).some(isPasteableMediaFile);
}

function EditorBodyInner(
    {
        value,
        onChange,
        onBlur,
        placeholder,
        autoFocus = false,
        onPasteFiles,
        overrideBanner = false,
        activePlatformLabel,
        onResetOverride,
        markerState,
        mentions = [],
        mentionPlatforms = [],
        savedMentions = [],
        onMentionsChange,
        onMentionNameChange,
        onApplySavedMention,
        onSaveMention,
        saveMentionProcessing = false,
        editable = true,
        emojiSkinTone = 'none',
        onEmojiInsert,
    }: EditorBodyProps,
    ref: Ref<EditorBodyHandle>,
) {
    const [activeMentionId, setActiveMentionId] = useState<string | null>(null);
    const previousMentionCount = useRef(mentions.length);
    const pendingFocusLabel = useRef<string | null>(null);
    // A zero-size element pinned to the active `@`; the picker popover anchors to
    // it so it floats beside the mention instead of inserting an in-flow bar that
    // would shove the editor down. `ready` gates the popover open until the anchor
    // has been positioned, so it never flashes at a stale spot on first open.
    const containerRef = useRef<HTMLDivElement>(null);
    const mentionAnchorRef = useRef<HTMLDivElement>(null);
    const mentionWasActive = useRef(false);
    const [mentionAnchorReady, setMentionAnchorReady] = useState(false);
    // Mirrors the emojiSuggest plugin's trigger state; see the `sync` effect
    // below. A zero-size anchor (mirroring the mention pattern) floats the
    // typeahead popover beside the active `:query`.
    const emojiAnchorRef = useRef<HTMLDivElement>(null);
    // Read live by the emojiSuggest plugin's handleKeyDown so key consumption
    // (Enter/Tab/Arrows/Escape) is gated on the popover actually being open,
    // not merely on a `:token` trigger being active — see emojiPopoverOpen.
    const emojiPopoverOpenRef = useRef(false);
    const [emojiSuggest, setEmojiSuggest] = useState<EmojiSuggestState>({
        active: false,
        query: '',
        from: 0,
        to: 0,
    });
    const [emojiMatches, setEmojiMatches] = useState<EmojiMatch[]>([]);
    const [emojiActiveIndex, setEmojiActiveIndex] = useState(0);
    const emojiDismissed = useRef(false);
    // Read by the CustomEvent handlers, which capture stale state otherwise.
    const emojiLatest = useRef({
        suggest: emojiSuggest,
        matches: emojiMatches,
        index: emojiActiveIndex,
    });
    emojiLatest.current = {
        suggest: emojiSuggest,
        matches: emojiMatches,
        index: emojiActiveIndex,
    };
    // editorProps is captured once at editor creation, but onPasteFiles is a
    // fresh closure each render (it reads the current media/limits). Route through
    // a ref so handlePaste always enforces the latest one-video / no-mixing rule.
    const onPasteFilesRef = useRef(onPasteFiles);
    onPasteFilesRef.current = onPasteFiles;
    const editor = useEditor({
        extensions: composerExtensions({
            placeholder,
            emojiOpenRef: emojiPopoverOpenRef,
        }),
        content: segmentsToDoc(value) as object,
        editable,
        editorProps: {
            handlePaste: (_view, event) => {
                const files = event.clipboardData?.files;
                if (!onPasteFilesRef.current || !hasPasteableMedia(files)) {
                    return false;
                }
                event.preventDefault();
                onPasteFilesRef.current(files as FileList);

                return true;
            },
        },
        onUpdate: ({ editor }) =>
            onChange(docToSegments(editor.getJSON() as DocNode)),
        onBlur,
    });

    useImperativeHandle(
        ref,
        () => ({
            insertText: (text: string) => {
                editor?.chain().focus().insertContent(text).run();
            },
        }),
        [editor],
    );

    useEffect(() => {
        if (!editor || !shouldFocusEditorOnMount(autoFocus, editable)) {
            return;
        }

        const frame = window.requestAnimationFrame(() => {
            editor.commands.focus('end');
        });

        return () => window.cancelAnimationFrame(frame);
    }, [editor, autoFocus, editable]);

    // Reflect editability changes (tiptap caches it from the initial options).
    useEffect(() => {
        editor?.setEditable(editable);
    }, [editor, editable]);

    // Keep the editor in sync when the value is replaced externally (tab switch,
    // conflict resolution) without emitting an update.
    useEffect(() => {
        if (!editor) {
            return;
        }
        const current = docToSegments(editor.getJSON() as DocNode);
        if (JSON.stringify(current) !== JSON.stringify(value)) {
            editor.commands.setContent(segmentsToDoc(value) as object, {
                emitUpdate: false,
            });
        }
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [value, editor]);

    // Focus the editor after `label` once it actually lands in the document.
    // Completing a mention updates `value` first, so the label may not be present
    // yet — in that case defer to the value-change effect below. Membership is
    // boundary-aware so a stale request can't be satisfied by an unrelated token
    // that merely contains the label as a substring.
    function tryFocusMentionLabel(label: string) {
        if (!editor) {
            return;
        }

        if (!editorContainsMentionLabel(editor, label)) {
            pendingFocusLabel.current = label;

            return;
        }

        pendingFocusLabel.current = null;
        requestAnimationFrame(() => {
            focusEditorAfterMentionLabel(editor, label);
        });
    }

    useEffect(() => {
        if (!editor || pendingFocusLabel.current === null) {
            return;
        }

        tryFocusMentionLabel(pendingFocusLabel.current);
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [value, editor]);

    // Push the active platform / split config into the section-markers plugin so
    // the inline thread markers reflect the tab the user is editing. Destructure
    // into primitives so the effect re-runs on value change, not object identity.
    const markerPlatform = markerState?.platform;
    const markerAutoSplit = markerState?.autoSplit;
    const markerLimit = markerState?.limit;
    const markerThreadMax = markerState?.threadMax;
    useEffect(() => {
        if (
            !editor ||
            markerPlatform === undefined ||
            markerAutoSplit === undefined ||
            markerLimit === undefined ||
            markerThreadMax === undefined
        ) {
            return;
        }
        editor.commands.setSectionMarkerState({
            platform: markerPlatform,
            autoSplit: markerAutoSplit,
            limit: markerLimit,
            threadMax: markerThreadMax,
        });
    }, [editor, markerPlatform, markerAutoSplit, markerLimit, markerThreadMax]);

    // Mirror the emojiSuggest plugin's trigger state into React on every
    // transaction (mirrors the section-markers → React bridge above).
    useEffect(() => {
        if (!editor) {
            return;
        }
        function sync() {
            setEmojiSuggest(
                emojiSuggestKey.getState(editor.state) ?? {
                    active: false,
                    query: '',
                    from: 0,
                    to: 0,
                },
            );
        }
        editor.on('transaction', sync);
        sync();

        return () => {
            editor.off('transaction', sync);
        };
    }, [editor]);

    // Fetch + rank matches when the query changes. A new query always clears
    // any prior dismissal, so re-typing after Escape reopens the popover.
    useEffect(() => {
        if (!emojiSuggest.active) {
            setEmojiMatches([]);

            return;
        }
        emojiDismissed.current = false;
        let cancelled = false;
        loadEmojiIndex()
            .then((index) => {
                if (cancelled) {
                    return;
                }
                setEmojiMatches(
                    rankEmoji(index, emojiSuggest.query, {
                        skinTone: emojiSkinTone,
                    }),
                );
                setEmojiActiveIndex(0);
            })
            .catch(() => {
                if (!cancelled) {
                    setEmojiMatches([]);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [emojiSuggest.active, emojiSuggest.query, emojiSkinTone]);

    // Replace the active `:query` with the chosen emoji and refocus.
    function insertEmojiAt(match: EmojiMatch, from: number, to: number) {
        if (!editor) {
            return;
        }
        editor
            .chain()
            .focus()
            .insertContentAt({ from, to }, `${match.emoji} `)
            .run();
        onEmojiInsert?.(match.emoji);
    }

    // Bridge the plugin's keyboard-forwarded CustomEvents to the typeahead's
    // React state. Reads from `emojiLatest` (not the render's closed-over
    // state) since these handlers are registered once per editor instance.
    useEffect(() => {
        const element = editor?.view.dom;
        if (!element) {
            return;
        }

        function onNav(event: Event) {
            const delta = (event as CustomEvent<{ delta: number }>).detail
                .delta;
            const count = emojiLatest.current.matches.length;
            if (count === 0) {
                return;
            }
            setEmojiActiveIndex((index) => (index + delta + count) % count);
        }

        function onCommit() {
            const { suggest, matches, index } = emojiLatest.current;
            const match = matches[index];
            if (match) {
                insertEmojiAt(match, suggest.from, suggest.to);
            }
        }

        function onDismiss() {
            emojiDismissed.current = true;
            setEmojiMatches([]);
        }

        element.addEventListener('composer:emoji-nav', onNav);
        element.addEventListener('composer:emoji-commit', onCommit);
        element.addEventListener('composer:emoji-dismiss', onDismiss);

        return () => {
            element.removeEventListener('composer:emoji-nav', onNav);
            element.removeEventListener('composer:emoji-commit', onCommit);
            element.removeEventListener('composer:emoji-dismiss', onDismiss);
        };
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [editor]);

    // Position the floating anchor at the start of the active `:query` so the
    // popover tracks the caret (reuses positionMentionAnchor's coord logic).
    useEffect(() => {
        const container = containerRef.current;
        const anchor = emojiAnchorRef.current;
        if (!editor || !emojiSuggest.active || !container || !anchor) {
            return;
        }
        const caret = editor.view.coordsAtPos(emojiSuggest.from);
        const rect = container.getBoundingClientRect();
        anchor.style.left = `${caret.left - rect.left}px`;
        anchor.style.top = `${caret.top - rect.top}px`;
        anchor.style.height = `${caret.bottom - caret.top}px`;
    }, [editor, emojiSuggest.active, emojiSuggest.from]);

    useEffect(() => {
        const element = editor?.view.dom;
        if (!element) {
            return;
        }

        function onMentionClick(event: Event) {
            const id = (event as CustomEvent<{ id?: string }>).detail?.id;
            if (id) {
                setActiveMentionId(id);
            }
        }

        element.addEventListener('composer:mention-click', onMentionClick);

        return () =>
            element.removeEventListener(
                'composer:mention-click',
                onMentionClick,
            );
    }, [editor]);

    useEffect(() => {
        if (mentions.length > previousMentionCount.current) {
            setActiveMentionId(mentions[mentions.length - 1]?.id ?? null);
        }
        if (
            activeMentionId &&
            mentions.length > 0 &&
            !mentions.some((mention) => mention.id === activeMentionId)
        ) {
            setActiveMentionId(mentions[mentions.length - 1]?.id ?? null);
        }
        previousMentionCount.current = mentions.length;
    }, [activeMentionId, mentions]);

    const activeMention =
        mentions.find((mention) => mention.id === activeMentionId) ?? null;
    const activePlatforms =
        mentionPlatforms.length > 0
            ? mentionPlatforms
            : ([markerPlatform ?? 'x'] as PlatformName[]);

    // Mirrored into a ref (read live by the emojiSuggest plugin's
    // handleKeyDown) so key consumption tracks the popover's actual open
    // state, not just an active `:token` trigger.
    const emojiPopoverOpen =
        editable &&
        emojiSuggest.active &&
        !emojiDismissed.current &&
        emojiMatches.length > 0;
    emojiPopoverOpenRef.current = emojiPopoverOpen;

    // Place the floating anchor at the active `@` only on the open transition.
    // The `@` does not move as the name is typed into the picker, so positioning
    // once keeps the popover put; re-running on every keystroke would needlessly
    // churn (and Radix would not re-measure an already-open popover anyway).
    function positionMentionAnchor(label: string) {
        const container = containerRef.current;
        const anchor = mentionAnchorRef.current;
        if (!editor || !container || !anchor) {
            return;
        }
        const pos =
            findMentionLabelStart(editor, label) ?? editor.state.selection.from;
        const caret = editor.view.coordsAtPos(pos);
        const rect = container.getBoundingClientRect();
        anchor.style.left = `${caret.left - rect.left}px`;
        anchor.style.top = `${caret.top - rect.top}px`;
        anchor.style.height = `${caret.bottom - caret.top}px`;
    }

    useEffect(() => {
        if (activeMention) {
            if (!mentionWasActive.current) {
                positionMentionAnchor(activeMention.label);
                setMentionAnchorReady(true);
            }
            mentionWasActive.current = true;

            return;
        }
        mentionWasActive.current = false;
        setMentionAnchorReady(false);
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [editor, activeMentionId]);

    function updateMention(
        previous: MentionPlaceholder,
        next: MentionPlaceholder,
    ) {
        if (previous.id !== next.id || previous.label !== next.label) {
            onMentionNameChange?.(previous, next);
            setActiveMentionId(next.id);

            return;
        }

        onMentionsChange?.(
            mentions.map((mention) =>
                mention.id === next.id ? next : mention,
            ),
        );
    }

    function completeMention(mention: MentionPlaceholder) {
        setActiveMentionId(null);
        tryFocusMentionLabel(mention.label);
    }

    // Discard an empty mention: drop the bare `@` from the editor and close the
    // picker. Triggered by Backspace in an empty search field or by Escape once
    // the typed name has been cleared.
    function removeActiveMention() {
        if (!activeMention || !editor) {
            return;
        }
        setActiveMentionId(null);
        removeMentionLabel(editor, activeMention.label);
    }

    // Escape in the picker is two-step: the first press clears the typed name
    // (back to a bare `@`), the second discards the now-empty mention entirely.
    function handleMentionEscape() {
        if (!activeMention) {
            return;
        }
        if (mentionInputValue(activeMention.label) !== '') {
            updateMention(activeMention, updateMentionName(activeMention, ''));

            return;
        }
        removeActiveMention();
    }

    return (
        <div ref={containerRef} className="relative">
            {overrideBanner && (
                <output
                    className={cn(
                        'flex items-center justify-between gap-3 border-y px-3 py-1.5 text-[11.5px] tracking-tight sm:px-[26px]',
                        'border-ring/25',
                        'bg-ring/5',
                        'text-foreground/85',
                    )}
                >
                    <span className="inline-flex min-w-0 items-center gap-1.5">
                        <Split
                            className="size-3.5 shrink-0 text-foreground/70"
                            aria-hidden="true"
                        />
                        <span className="truncate">
                            <span className="font-medium">
                                {activePlatformLabel
                                    ? `Editing override for ${activePlatformLabel}`
                                    : 'Override active'}
                            </span>
                            <span className="text-muted-foreground">
                                {' '}
                                — edits apply only here.
                            </span>
                        </span>
                    </span>
                    {onResetOverride && (
                        <button
                            type="button"
                            onClick={onResetOverride}
                            className="shrink-0 rounded-md px-2 py-0.5 text-[11.5px] font-medium text-foreground/80 transition-colors hover:bg-background hover:text-foreground"
                        >
                            Reset to base
                        </button>
                    )}
                </output>
            )}
            <Popover
                open={
                    !!(editable && onMentionsChange && activeMention) &&
                    mentionAnchorReady
                }
                onOpenChange={(open) => {
                    if (!open) {
                        setActiveMentionId(null);
                    }
                }}
            >
                <PopoverAnchor asChild>
                    <div
                        ref={mentionAnchorRef}
                        aria-hidden
                        className="pointer-events-none absolute w-0"
                    />
                </PopoverAnchor>
                {activeMention && (
                    <PopoverContent
                        align="start"
                        side="bottom"
                        sideOffset={8}
                        className="w-80 gap-0 rounded-2xl p-2"
                        onOpenAutoFocus={(event) => event.preventDefault()}
                        onEscapeKeyDown={(event) => {
                            event.preventDefault();
                            handleMentionEscape();
                        }}
                    >
                        <MentionPicker
                            activeMention={activeMention}
                            savedMentions={savedMentions}
                            activePlatforms={activePlatforms}
                            onApplySavedMention={(saved) => {
                                onApplySavedMention?.(activeMention, saved);
                            }}
                            onUpdateMention={updateMention}
                            onSaveMention={onSaveMention}
                            saveMentionProcessing={saveMentionProcessing}
                            onMentionComplete={completeMention}
                            onRemoveMention={removeActiveMention}
                        />
                    </PopoverContent>
                )}
            </Popover>
            <Popover open={emojiPopoverOpen}>
                <PopoverAnchor asChild>
                    <div
                        ref={emojiAnchorRef}
                        aria-hidden
                        className="pointer-events-none absolute w-0"
                    />
                </PopoverAnchor>
                <PopoverContent
                    align="start"
                    side="bottom"
                    sideOffset={8}
                    className="w-auto rounded-xl p-0"
                    onOpenAutoFocus={(event) => event.preventDefault()}
                >
                    <EmojiSuggestList
                        matches={emojiMatches}
                        activeIndex={emojiActiveIndex}
                        onSelect={(match) =>
                            insertEmojiAt(
                                match,
                                emojiLatest.current.suggest.from,
                                emojiLatest.current.suggest.to,
                            )
                        }
                    />
                </PopoverContent>
            </Popover>
            <div className="px-4 pt-[22px] pb-[18px] sm:px-[26px]">
                <EditorContent
                    editor={editor}
                    className="max-w-none text-[16px] leading-5 tracking-[-0.005em] text-foreground focus:outline-none [&_.ProseMirror]:outline-none [&_.ProseMirror_p]:m-0 [&_.ProseMirror_p+p]:mt-0.5!"
                />
            </div>
        </div>
    );
}

const EditorBody = forwardRef(EditorBodyInner);
export default EditorBody;
