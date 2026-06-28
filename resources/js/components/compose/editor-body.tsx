import { EditorContent, useEditor } from '@tiptap/react';
import { Split } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    mentionInputValue,
    setPlatformMentionMode,
    updateMentionHandle,
    updateMentionName,
    usesPlatformMention,
} from '@/lib/compose/mentions';
import {
    docToSegments,
    segmentsToDoc,
    type DocNode,
} from '@/lib/compose/tiptap-doc';
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

export function shouldSelectMentionNameInput(
    input: HTMLInputElement | null,
    activeElement: Element | null,
): boolean {
    return !!input && input !== activeElement;
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
    const mentionNameInput = useRef<HTMLInputElement>(null);
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

    useEffect(() => {
        if (!activeMentionId) {
            return;
        }

        const frame = window.requestAnimationFrame(() => {
            mentionNameInput.current?.focus();
            if (
                shouldSelectMentionNameInput(
                    mentionNameInput.current,
                    document.activeElement,
                )
            ) {
                mentionNameInput.current?.select();
            }
        });

        return () => window.cancelAnimationFrame(frame);
    }, [activeMentionId]);

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
                            className="w-80 gap-3 rounded-2xl p-3"
                        >
                            <div>
                                <div className="text-xs font-medium text-muted-foreground">
                                    Mention Name
                                </div>
                                <InputGroup className="mt-1.5 h-9 rounded-lg border-border bg-background">
                                    <InputGroupAddon>@</InputGroupAddon>
                                    <InputGroupInput
                                        ref={mentionNameInput}
                                        value={mentionInputValue(
                                            activeMention.label,
                                        )}
                                        placeholder="name"
                                        aria-label="Mention name shown in the post"
                                        onChange={(event) =>
                                            updateMention(
                                                activeMention,
                                                updateMentionName(
                                                    activeMention,
                                                    event.target.value,
                                                ),
                                            )
                                        }
                                    />
                                </InputGroup>
                            </div>
                            <div className="text-xs font-medium text-muted-foreground">
                                Platform handles
                            </div>
                            <div className="flex flex-col gap-2">
                                {activePlatforms.map((platform, index) => {
                                    const canUseMention =
                                        platform !== 'linkedin';
                                    const useMention =
                                        canUseMention &&
                                        usesPlatformMention(
                                            activeMention,
                                            platform,
                                        );
                                    const value =
                                        activeMention.handles[platform] ??
                                        activeMention.label;

                                    return (
                                        <label
                                            key={platform}
                                            className="flex flex-col gap-1.5 text-xs"
                                        >
                                            <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                                <PlatformGlyph
                                                    platform={platform}
                                                    size={14}
                                                    className="text-foreground"
                                                />
                                                <span className="capitalize">
                                                    {platform}
                                                </span>
                                            </span>
                                            <div className="flex gap-2">
                                                <InputGroup className="h-9 min-w-0 flex-1 rounded-lg border-border bg-background">
                                                    {useMention && (
                                                        <InputGroupAddon>
                                                            @
                                                        </InputGroupAddon>
                                                    )}
                                                    <InputGroupInput
                                                        value={mentionInputValue(
                                                            value,
                                                        )}
                                                        placeholder={
                                                            useMention
                                                                ? 'handle'
                                                                : 'display name'
                                                        }
                                                        aria-label={`${platform} ${
                                                            useMention
                                                                ? 'handle'
                                                                : 'display text'
                                                        } for ${activeMention.label}`}
                                                        onChange={(event) =>
                                                            updateMention(
                                                                activeMention,
                                                                updateMentionHandle(
                                                                    activeMention,
                                                                    platform,
                                                                    event.target
                                                                        .value,
                                                                    useMention,
                                                                ),
                                                            )
                                                        }
                                                        autoFocus={index === 0}
                                                    />
                                                </InputGroup>
                                                {canUseMention && (
                                                    <button
                                                        type="button"
                                                        className="h-9 shrink-0 rounded-lg border border-border px-2.5 text-xs font-medium text-primary transition-colors hover:bg-primary/10"
                                                        onClick={() =>
                                                            updateMention(
                                                                activeMention,
                                                                setPlatformMentionMode(
                                                                    activeMention,
                                                                    platform,
                                                                    !useMention,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        {useMention
                                                            ? 'Use text only'
                                                            : 'Use @ mention'}
                                                    </button>
                                                )}
                                            </div>
                                        </label>
                                    );
                                })}
                            </div>
                            {onSaveMention && (
                                <button
                                    type="button"
                                    disabled={saveMentionProcessing}
                                    onClick={() =>
                                        void onSaveMention(activeMention)
                                    }
                                    className="rounded-lg bg-primary px-3 py-2 text-xs font-medium text-primary-foreground transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {saveMentionProcessing
                                        ? 'Saving…'
                                        : 'Save to workspace'}
                                </button>
                            )}
                            {savedMentions.length > 0 && (
                                <div className="flex flex-col gap-2 border-t border-border/70 pt-3">
                                    <div className="text-xs font-medium text-muted-foreground">
                                        Saved mentions
                                    </div>
                                    <div className="flex flex-col gap-1">
                                        {savedMentions.map((saved) => (
                                            <button
                                                key={saved.id}
                                                type="button"
                                                onClick={() => {
                                                    onApplySavedMention?.(
                                                        activeMention,
                                                        saved,
                                                    );
                                                    setActiveMentionId(
                                                        saved.name
                                                            .replace(/^@/, '')
                                                            .toLowerCase()
                                                            .replace(
                                                                /[^a-z0-9_-]+/g,
                                                                '-',
                                                            ),
                                                    );
                                                }}
                                                className="flex items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-left text-sm transition-colors hover:bg-muted"
                                            >
                                                <span className="font-medium text-foreground">
                                                    {saved.name}
                                                </span>
                                                <span className="flex items-center gap-1 text-muted-foreground">
                                                    {activePlatforms
                                                        .filter(
                                                            (platform) =>
                                                                saved.handles[
                                                                    platform
                                                                ],
                                                        )
                                                        .map((platform) => (
                                                            <PlatformGlyph
                                                                key={platform}
                                                                platform={
                                                                    platform
                                                                }
                                                                size={13}
                                                            />
                                                        ))}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </PopoverContent>
                    </Popover>
                </div>
            )}
            <div className="px-4 pt-[22px] pb-[18px] sm:px-[26px]">
                <EditorContent
                    editor={editor}
                    className="max-w-none text-[16px] leading-[1.65] tracking-[-0.005em] text-foreground focus:outline-none [&_.ProseMirror]:outline-none"
                />
            </div>
        </div>
    );
}
