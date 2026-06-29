import { EditorContent, useEditor } from '@tiptap/react';
import { Split } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import MentionPicker from '@/components/compose/mention-picker';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { mentionInputValue, updateMentionName } from '@/lib/compose/mentions';
import {
    docToSegments,
    segmentsToDoc,
    type DocNode,
} from '@/lib/compose/tiptap-doc';
import {
    editorContainsMentionLabel,
    focusEditorAfterMentionLabel,
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

export default function EditorBody({
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
}: EditorBodyProps) {
    const [activeMentionId, setActiveMentionId] = useState<string | null>(null);
    const previousMentionCount = useRef(mentions.length);
    const pendingFocusLabel = useRef<string | null>(null);
    // editorProps is captured once at editor creation, but onPasteFiles is a
    // fresh closure each render (it reads the current media/limits). Route through
    // a ref so handlePaste always enforces the latest one-video / no-mixing rule.
    const onPasteFilesRef = useRef(onPasteFiles);
    onPasteFilesRef.current = onPasteFiles;
    const editor = useEditor({
        extensions: composerExtensions({ placeholder }),
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

    // Escape in the picker is two-step: the first press clears the typed name
    // (back to a bare `@`), the second closes the picker. The `@` itself stays
    // in the editor so a literal `@` can still be typed, and focus returns to
    // the editor so typing can continue right after it.
    function handleMentionEscape() {
        if (!activeMention) {
            return;
        }
        if (mentionInputValue(activeMention.label) !== '') {
            updateMention(activeMention, updateMentionName(activeMention, ''));

            return;
        }
        setActiveMentionId(null);
        requestAnimationFrame(() => editor?.commands.focus());
    }

    return (
        <div className="relative">
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
            {editable && onMentionsChange && activeMention && (
                <div className="flex items-center border-b border-border/70 px-4 py-2 sm:px-[26px]">
                    <Popover
                        open
                        onOpenChange={(open) => {
                            if (!open) {
                                setActiveMentionId(null);
                            }
                        }}
                    >
                        <PopoverTrigger asChild>
                            <button
                                type="button"
                                className="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary shadow-sm"
                            >
                                <PlatformGlyph
                                    platform={activePlatforms[0] ?? 'x'}
                                    size={12}
                                    className="shrink-0"
                                />
                                {activeMention.label}
                            </button>
                        </PopoverTrigger>
                        <PopoverContent
                            align="start"
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
                            />
                        </PopoverContent>
                    </Popover>
                </div>
            )}
            <div className="px-4 pt-[22px] pb-[18px] sm:px-[26px]">
                <EditorContent
                    editor={editor}
                    className="max-w-none text-[16px] leading-5 tracking-[-0.005em] text-foreground focus:outline-none [&_.ProseMirror]:outline-none [&_.ProseMirror_p]:m-0 [&_.ProseMirror_p+p]:mt-0.5!"
                />
            </div>
        </div>
    );
}
