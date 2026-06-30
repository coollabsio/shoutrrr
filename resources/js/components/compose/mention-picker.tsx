import { ArrowLeft, Plus } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type RefObject } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    mentionInputValue,
    normalizeMentionName,
    savedMentionToPlaceholder,
    setPlatformMentionMode,
    updateMentionHandle,
    updateMentionName,
    usesPlatformMention,
} from '@/lib/compose/mentions';
import { cn } from '@/lib/utils';
import type {
    MentionPlaceholder,
    PlatformName,
    WorkspaceMention,
} from '@/types/compose';

export type MentionPickerMode = 'search' | 'edit';

type MentionPickerProps = {
    activeMention: MentionPlaceholder;
    savedMentions: WorkspaceMention[];
    activePlatforms: PlatformName[];
    onApplySavedMention: (saved: WorkspaceMention) => void;
    onUpdateMention: (
        previous: MentionPlaceholder,
        next: MentionPlaceholder,
    ) => void;
    onSaveMention?: (mention: MentionPlaceholder) => Promise<void>;
    saveMentionProcessing?: boolean;
    onMentionComplete?: (mention: MentionPlaceholder) => void;
    /** Discard the in-progress mention (Backspace on an empty search field). */
    onRemoveMention?: () => void;
};

export function savedMentionSearchKeywords(
    saved: WorkspaceMention,
    platforms: PlatformName[],
): string[] {
    const keywords: string[] = [];

    for (const platform of platforms) {
        const handle = saved.handles[platform];
        if (handle) {
            keywords.push(mentionInputValue(handle));
        }
    }

    return keywords;
}

export function shouldFocusMentionPickerSearch(
    input: HTMLInputElement | null,
    activeElement: Element | null,
): boolean {
    return !!input && input !== activeElement;
}

export function mentionFilter(
    value: string,
    search: string,
    keywords?: string[],
): number {
    const needle = search.trim().toLowerCase();

    if (needle === '') {
        return 1;
    }

    const tokens = [value, ...(keywords ?? [])].map((token) =>
        mentionInputValue(token).toLowerCase(),
    );
    if (tokens.some((token) => token.startsWith(needle))) {
        return 1;
    }
    if (tokens.some((token) => token.includes(needle))) {
        return 0.5;
    }

    return 0;
}

export default function MentionPicker({
    activeMention,
    savedMentions,
    activePlatforms,
    onApplySavedMention,
    onUpdateMention,
    onSaveMention,
    saveMentionProcessing = false,
    onMentionComplete,
    onRemoveMention,
}: MentionPickerProps) {
    const [mode, setMode] = useState<MentionPickerMode>('search');
    const searchInputRef = useRef<HTMLInputElement>(null);
    const mentionNameInputRef = useRef<HTMLInputElement>(null);
    const search = mentionInputValue(activeMention.label);
    const createLabel = normalizeMentionName(search);

    // Focus (and select) whichever field the current mode exposes. Both modes
    // share the same defer-a-frame-then-focus dance, so they live in one effect.
    // It also re-runs when the active mention changes — a different chip clicked
    // or a brand-new @mention — so focus follows it. Renaming the current mention
    // re-runs this too (its id changes on every keystroke), but the field is
    // already focused then, so the early return leaves the caret untouched.
    useEffect(() => {
        const ref = mode === 'edit' ? mentionNameInputRef : searchInputRef;

        if (ref.current && ref.current === document.activeElement) {
            return;
        }

        const frame = window.requestAnimationFrame(() => {
            const input = ref.current;
            if (!input) {
                return;
            }
            const shouldSelect = shouldFocusMentionPickerSearch(
                input,
                document.activeElement,
            );
            input.focus();
            if (shouldSelect) {
                input.select();
            }
        });

        return () => window.cancelAnimationFrame(frame);
    }, [mode, activeMention.id]);

    const savedMentionKeywords = useMemo(
        () =>
            new Map(
                savedMentions.map((saved) => [
                    saved.id,
                    savedMentionSearchKeywords(saved, activePlatforms),
                ]),
            ),
        // Keyed on the platform set by value: activePlatforms is a fresh array
        // each render, so depending on its identity would defeat the memo.
        // oxlint-disable-next-line react-hooks/exhaustive-deps
        [savedMentions, activePlatforms.join(',')],
    );

    function selectSaved(saved: WorkspaceMention) {
        onApplySavedMention(saved);
        onMentionComplete?.(savedMentionToPlaceholder(saved));
    }

    async function saveAndComplete(mention: MentionPlaceholder) {
        if (!onSaveMention) {
            return;
        }

        await onSaveMention(mention);
        onMentionComplete?.(mention);
    }

    function openCreateMode() {
        // `search` is derived from activeMention.label, so the typed name is
        // already applied live via the input's onValueChange — no rename needed.
        setMode('edit');
    }

    if (mode === 'edit') {
        return (
            <div className="flex flex-col gap-3">
                <button
                    type="button"
                    onClick={() => setMode('search')}
                    className="inline-flex items-center gap-1.5 self-start rounded-md px-1 py-0.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    <ArrowLeft className="size-3.5" aria-hidden />
                    Back to search
                </button>
                <MentionHandleEditor
                    activeMention={activeMention}
                    activePlatforms={activePlatforms}
                    mentionNameInputRef={mentionNameInputRef}
                    onUpdateMention={onUpdateMention}
                    onSave={
                        onSaveMention
                            ? () => void saveAndComplete(activeMention)
                            : undefined
                    }
                    saveMentionProcessing={saveMentionProcessing}
                />
            </div>
        );
    }

    return (
        <Command
            filter={mentionFilter}
            className="rounded-xl bg-transparent p-0"
        >
            <CommandInput
                ref={searchInputRef}
                value={search}
                placeholder="Search saved mentions…"
                aria-label="Mention name shown in the post"
                onValueChange={(value) =>
                    onUpdateMention(
                        activeMention,
                        updateMentionName(activeMention, value),
                    )
                }
                onKeyDown={(event) => {
                    if (event.key === 'Backspace' && search === '') {
                        event.preventDefault();
                        onRemoveMention?.();
                    }
                }}
            />
            <CommandList className="max-h-56">
                <CommandEmpty className="py-6 text-center text-xs text-muted-foreground">
                    Type a name to create a mention
                </CommandEmpty>
                {savedMentions.length > 0 && (
                    <CommandGroup heading="Saved mentions">
                        {savedMentions.map((saved) => (
                            <CommandItem
                                key={saved.id}
                                value={saved.name}
                                keywords={savedMentionKeywords.get(saved.id)}
                                onSelect={() => selectSaved(saved)}
                                className="flex items-center justify-between gap-3"
                            >
                                <span className="font-medium">
                                    {saved.name}
                                </span>
                                <span className="flex items-center gap-1 text-muted-foreground">
                                    {activePlatforms
                                        .filter(
                                            (platform) =>
                                                saved.handles[platform],
                                        )
                                        .map((platform) => (
                                            <PlatformGlyph
                                                key={platform}
                                                platform={platform}
                                                size={13}
                                            />
                                        ))}
                                </span>
                            </CommandItem>
                        ))}
                    </CommandGroup>
                )}
                {search.trim() !== '' && (
                    <>
                        {savedMentions.length > 0 && <CommandSeparator />}
                        <CommandGroup>
                            <CommandItem
                                value={`__create__${createLabel}`}
                                onSelect={openCreateMode}
                                className="text-primary"
                            >
                                <Plus className="size-3.5" aria-hidden />
                                Create {createLabel} as new mention
                            </CommandItem>
                        </CommandGroup>
                    </>
                )}
            </CommandList>
        </Command>
    );
}

type MentionHandleEditorProps = {
    activeMention: MentionPlaceholder;
    activePlatforms: PlatformName[];
    mentionNameInputRef: RefObject<HTMLInputElement | null>;
    onUpdateMention: (
        previous: MentionPlaceholder,
        next: MentionPlaceholder,
    ) => void;
    /** Omitted when workspace saving is unsupported, which hides the Save button. */
    onSave?: () => void;
    saveMentionProcessing?: boolean;
};

function MentionHandleEditor({
    activeMention,
    activePlatforms,
    mentionNameInputRef,
    onUpdateMention,
    onSave,
    saveMentionProcessing = false,
}: MentionHandleEditorProps) {
    return (
        <>
            <div>
                <div className="text-xs font-medium text-muted-foreground">
                    Mention name
                </div>
                <InputGroup className="mt-1.5 h-9 rounded-lg border-border bg-background">
                    <InputGroupAddon>@</InputGroupAddon>
                    <InputGroupInput
                        ref={mentionNameInputRef}
                        value={mentionInputValue(activeMention.label)}
                        placeholder="name"
                        aria-label="Mention name shown in the post"
                        onChange={(event) =>
                            onUpdateMention(
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
                {activePlatforms.map((platform) => {
                    const canUseMention = platform !== 'linkedin';
                    const useMention =
                        canUseMention &&
                        usesPlatformMention(activeMention, platform);
                    const handleValue =
                        activeMention.handles[platform] ?? activeMention.label;

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
                                <span className="capitalize">{platform}</span>
                            </span>
                            <div className="flex gap-2">
                                <InputGroup className="h-9 min-w-0 flex-1 rounded-lg border-border bg-background">
                                    {useMention && (
                                        <InputGroupAddon>@</InputGroupAddon>
                                    )}
                                    <InputGroupInput
                                        value={mentionInputValue(handleValue)}
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
                                            onUpdateMention(
                                                activeMention,
                                                updateMentionHandle(
                                                    activeMention,
                                                    platform,
                                                    event.target.value,
                                                    useMention,
                                                ),
                                            )
                                        }
                                    />
                                </InputGroup>
                                {canUseMention && (
                                    <button
                                        type="button"
                                        className="h-9 shrink-0 rounded-lg border border-border px-2.5 text-xs font-medium text-primary transition-colors hover:bg-primary/10"
                                        onClick={() =>
                                            onUpdateMention(
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
            {onSave && (
                <button
                    type="button"
                    disabled={
                        saveMentionProcessing ||
                        mentionInputValue(activeMention.label).trim() === ''
                    }
                    onClick={onSave}
                    className={cn(
                        'rounded-lg bg-primary px-3 py-2 text-xs font-medium text-primary-foreground transition-colors hover:bg-primary/90',
                        'disabled:cursor-not-allowed disabled:opacity-60',
                    )}
                >
                    {saveMentionProcessing ? 'Saving…' : 'Save to workspace'}
                </button>
            )}
        </>
    );
}
