import { EditorContent, useEditor } from '@tiptap/react';
import type { ReactNode, Ref } from 'react';
import {
    forwardRef,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';

import EmojiSuggestPopover from '@/components/compose/emoji-suggest-popover';
import MentionPicker from '@/components/compose/mention-picker';
import { Split } from '@/components/ui/icons';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { useEmojiTypeahead } from '@/hooks/compose/use-emoji-typeahead';
import { activeSegmentRef } from '@/lib/compose/active-segment';
import type { EmojiSkinTone } from '@/lib/compose/emoji/types';
import { mentionInputValue, updateMentionName } from '@/lib/compose/mentions';
import {
    docToSegments,
    docToSegmentsWithBreaks,
    segmentsToDocWithBreaks,
    type DocNode,
} from '@/lib/compose/tiptap-doc';
import {
    editorContainsMentionLabel,
    findMentionLabelStart,
    focusEditorAfterMentionLabel,
    removeMentionLabel,
} from '@/lib/compose/tiptap/mention-focus';
import type { SegmentAnchor } from '@/lib/compose/tiptap/segment-media-anchors';
import { composerExtensions } from '@/lib/compose/tiptap/setup';
import { cn } from '@/lib/utils';
import type {
    MentionPlaceholder,
    PlatformName,
    WorkspaceMention,
} from '@/types/compose';

type EditorBodyProps = {
    value: string[];
    /**
     * Stable ids for the section breaks between `value`'s segments
     * (`breakIds.length === value.length - 1`) — round-tripped back onto the
     * rebuilt doc's `sectionBreak` nodes so a fresh mount or an external
     * content reset doesn't mint new ids for breaks that already exist (which
     * would orphan anything keyed to the old ones, e.g. per-segment media
     * placements). Omit for callers with no thread structure to preserve.
     */
    breakIds?: string[];
    /**
     * Fires on every doc change with the segment texts and the stable break
     * ids that separate them (`breakIds.length === segments.length - 1`) —
     * see `docToSegmentsWithBreaks`. Callers that don't care about thread
     * structure (e.g. the single-message reply box) can ignore the second
     * argument.
     */
    onChange: (segments: string[], breakIds: string[]) => void;
    onBlur?: () => void;
    placeholder?: string;
    /** When false, the post is read-only (e.g. already published/scheduled). */
    editable?: boolean;
    /**
     * Single-message variant (the engagement reply box): drops the thread
     * extensions and shrinks the type scale. See `composerExtensions`
     * for why the extensions must be omitted rather than left unconfigured.
     */
    compact?: boolean;
    /** Fired on ⌘/Ctrl+Enter. Omit to leave the shortcut unhandled. */
    onSubmit?: () => void;
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
    onDeleteMention?: (saved: WorkspaceMention) => void | Promise<void>;
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
    /**
     * Fired whenever the caret/selection moves into a different segment
     * (Tiptap `onSelectionUpdate`). Omit if the caller doesn't need live
     * active-segment tracking (e.g. it only reads it on demand via the handle).
     */
    onActiveSegmentChange?: (segmentRef: string) => void;
    /**
     * Render the per-segment media row for a given segment ref, portaled INTO
     * the editor just above that segment's division line (or below the last
     * segment). Omit for the compact reply editor, which has no thread
     * structure. Returning `null` leaves that segment's anchor empty.
     */
    renderSegmentMedia?: (segmentRef: string) => ReactNode;
};

export type EditorBodyHandle = {
    /** Insert text (e.g. an emoji) at the current selection and refocus. */
    insertText: (text: string) => void;
    /** Move focus into the editor with the caret at the end. */
    focus: () => void;
    /** Release focus from the editor. */
    blur: () => void;
    /** Pull-based read of the segment ref the caret currently sits in. */
    activeSegmentRef: () => string;
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

/**
 * ⌘/Ctrl+Enter — the send shortcut. Plain Enter is deliberately excluded: it
 * inserts a newline, and the emoji typeahead claims it while its popover is open.
 */
export function isSubmitShortcut(event: {
    key: string;
    metaKey: boolean;
    ctrlKey: boolean;
}): boolean {
    return (event.metaKey || event.ctrlKey) && event.key === 'Enter';
}

function EditorBodyInner(
    {
        value,
        breakIds = [],
        onChange,
        onBlur,
        placeholder,
        autoFocus = false,
        compact = false,
        onSubmit,
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
        onDeleteMention,
        editable = true,
        emojiSkinTone = 'none',
        onEmojiInsert,
        onActiveSegmentChange,
        renderSegmentMedia,
    }: EditorBodyProps,
    ref: Ref<EditorBodyHandle>,
) {
    // Per-segment media-row anchors (DOM nodes the SegmentMediaAnchors plugin
    // inserts into the editor flow); each hosts a portaled media row.
    const [segmentAnchors, setSegmentAnchors] = useState<SegmentAnchor[]>([]);
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
    // Written live by useEmojiTypeahead and read by the emojiSuggest plugin's
    // handleKeyDown so key consumption is gated on the popover actually being
    // open. Created here because composerExtensions needs it before the editor
    // exists, and the hook needs the editor after — one shared ref bridges them.
    const emojiOpenRef = useRef(false);
    // editorProps is captured once at editor creation, but onPasteFiles is a
    // fresh closure each render (it reads the current media/limits). Route through
    // a ref so handlePaste always enforces the latest one-video / no-mixing rule.
    const onPasteFilesRef = useRef(onPasteFiles);
    onPasteFilesRef.current = onPasteFiles;
    // Same reason as onPasteFilesRef: onSubmit reads the caller's current send
    // state (text, uploads in flight), so it must not be frozen into editorProps.
    const onSubmitRef = useRef(onSubmit);
    onSubmitRef.current = onSubmit;
    const editor = useEditor({
        extensions: composerExtensions({
            placeholder,
            emojiOpenRef,
            compact,
        }),
        content: segmentsToDocWithBreaks(value, breakIds) as object,
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
            handleKeyDown: (_view, event) => {
                if (!onSubmitRef.current || !isSubmitShortcut(event)) {
                    return false;
                }
                event.preventDefault();
                onSubmitRef.current();

                return true;
            },
        },
        onUpdate: ({ editor }) => {
            const { segments, breakIds } = docToSegmentsWithBreaks(
                editor.getJSON() as DocNode,
            );
            onChange(segments, breakIds);
        },
        onSelectionUpdate: ({ editor }) => {
            if (!onActiveSegmentChange) {
                return;
            }
            // Avoid `editor.getJSON()` + `docToSegmentsWithBreaks` here — both
            // walk and serialize the *entire* document (every paragraph's
            // text/marks), which is wasteful on a handler that fires on every
            // caret move, not just on content changes. `activeSegmentRef` only
            // needs each top-level node's break id (if any) and size, both of
            // which the live ProseMirror doc already exposes in O(1) per node.
            const breakIds: string[] = [];
            const content: DocNode[] = [];
            editor.state.doc.forEach((node) => {
                if (node.type.name === 'sectionBreak') {
                    const breakId =
                        (node.attrs.breakId as string | undefined) ??
                        'b' + breakIds.length;
                    breakIds.push(breakId);
                    content.push({ type: 'sectionBreak' });

                    return;
                }
                // A same-size stand-in so `activeSegmentRef`'s node-size math
                // (which reads text length, not full JSON) lines up with the
                // real document without re-serializing its actual content.
                content.push({
                    type: node.type.name,
                    content: [
                        {
                            type: 'text',
                            text: 'x'.repeat(Math.max(node.nodeSize - 2, 0)),
                        },
                    ],
                });
            });
            onActiveSegmentChange(
                activeSegmentRef(
                    { type: 'doc', content },
                    editor.state.selection.from,
                    breakIds,
                ),
            );
        },
        onBlur,
    });

    const emoji = useEmojiTypeahead({
        editor,
        containerRef,
        openRef: emojiOpenRef,
        skinTone: emojiSkinTone,
        editable,
        onInsert: onEmojiInsert,
    });

    useImperativeHandle(
        ref,
        () => ({
            insertText: (text: string) => {
                editor?.chain().focus().insertContent(text).run();
            },
            focus: () => {
                editor?.commands.focus('end');
            },
            blur: () => {
                editor?.commands.blur();
            },
            activeSegmentRef: () => {
                if (!editor) {
                    return '__head__';
                }
                const doc = editor.getJSON() as DocNode;
                const { breakIds } = docToSegmentsWithBreaks(doc);

                return activeSegmentRef(
                    doc,
                    editor.state.selection.from,
                    breakIds,
                );
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

    // Read the per-segment media anchors straight off the rendered DOM after
    // each doc change, rather than having the plugin push them via a callback
    // (which can fire mid-render and get dropped). The anchor nodes are stable
    // (cached per ref by the plugin), so we only re-set state when the ref/el
    // set actually changes.
    useEffect(() => {
        if (!editor || compact) {
            return;
        }
        const read = () => {
            const next = Array.from(
                editor.view.dom.querySelectorAll<HTMLElement>(
                    '.segment-media-anchor',
                ),
            ).map((el) => ({
                ref: el.getAttribute('data-segment-ref') ?? '__head__',
                el,
            }));
            setSegmentAnchors((prev) =>
                prev.length === next.length &&
                prev.every(
                    (a, i) => a.ref === next[i].ref && a.el === next[i].el,
                )
                    ? prev
                    : next,
            );
        };
        read();
        const frame = window.requestAnimationFrame(read);
        editor.on('update', read);

        return () => {
            window.cancelAnimationFrame(frame);
            editor.off('update', read);
        };
    }, [editor, compact]);

    // Keep the editor in sync when the value is replaced externally (tab switch,
    // conflict resolution) without emitting an update.
    useEffect(() => {
        if (!editor) {
            return;
        }
        const current = docToSegments(editor.getJSON() as DocNode);
        if (JSON.stringify(current) !== JSON.stringify(value)) {
            editor.commands.setContent(
                segmentsToDocWithBreaks(value, breakIds) as object,
                { emitUpdate: false },
            );
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
                onOpenChange={(open, eventDetails) => {
                    if (!open && eventDetails.reason === 'escape-key') {
                        eventDetails.cancel();
                        handleMentionEscape();
                        return;
                    }
                    if (!open) {
                        setActiveMentionId(null);
                    }
                }}
            >
                <div
                    ref={mentionAnchorRef}
                    aria-hidden
                    className="pointer-events-none absolute w-0"
                />
                {activeMention && (
                    <PopoverContent
                        anchor={mentionAnchorRef}
                        align="start"
                        side="bottom"
                        sideOffset={8}
                        className="w-96 gap-0 rounded-2xl p-3"
                        initialFocus={false}
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
                            onDeleteMention={onDeleteMention}
                            onMentionComplete={completeMention}
                            onRemoveMention={removeActiveMention}
                        />
                    </PopoverContent>
                )}
            </Popover>
            <EmojiSuggestPopover
                open={emoji.open}
                onDismiss={emoji.dismiss}
                anchorRef={emoji.anchorRef}
                matches={emoji.matches}
                activeIndex={emoji.activeIndex}
                onSelect={emoji.select}
            />
            <div
                className={cn(
                    compact
                        ? 'px-3 py-2'
                        : 'px-4 pt-[22px] pb-[18px] sm:px-[26px]',
                )}
            >
                <EditorContent
                    editor={editor}
                    className={cn(
                        'max-w-none leading-5 tracking-[-0.005em] text-foreground focus:outline-none [&_.ProseMirror]:outline-none [&_.ProseMirror_p]:m-0 [&_.ProseMirror_p+p]:mt-0.5!',
                        compact
                            ? // Replaces the old textarea's rows={3}: roughly three
                              // lines tall, then scrolls instead of growing the pane.
                              // The min-height sits on .ProseMirror, not this wrapper,
                              // so the whole box is clickable-to-focus the way the
                              // textarea it replaced was.
                              'max-h-32 overflow-y-auto text-[14px] [&_.ProseMirror]:min-h-[3.75rem]'
                            : 'text-[16px]',
                    )}
                />
            </div>
            {/* Per-segment media rows, portaled into anchor nodes the
            SegmentMediaAnchors plugin places at each segment's end so they sit
            just above that thread post's division line. */}
            {renderSegmentMedia !== undefined &&
                segmentAnchors.map((anchor) =>
                    createPortal(
                        renderSegmentMedia(anchor.ref),
                        anchor.el,
                        anchor.ref,
                    ),
                )}
        </div>
    );
}

const EditorBody = forwardRef(EditorBodyInner);
export default EditorBody;
