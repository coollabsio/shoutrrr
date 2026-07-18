import { ArrowLeft, Building2, Info, Pencil, Plus, X } from 'lucide-react';
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
    extractLinkedInOrgRef,
    hasEmptyActiveHandle,
    mentionInputValue,
    normalizeMentionName,
    platformSupportsMention,
    savedMentionToPlaceholder,
    setPlatformMentionMode,
    updateMentionHandle,
    updateMentionLinkedInUrn,
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

export function editSavedMention(saved: WorkspaceMention): MentionPlaceholder {
    return savedMentionToPlaceholder(saved);
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

    function editSaved(saved: WorkspaceMention) {
        onUpdateMention(activeMention, editSavedMention(saved));
        setMode('edit');
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
            <div className="flex flex-col gap-2.5">
                <button
                    type="button"
                    onClick={() => setMode('search')}
                    className="-mb-0.5 inline-flex items-center gap-1.5 self-start rounded-md px-1 py-0.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
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
                                className="relative flex items-center gap-3 pr-9"
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
                                <button
                                    type="button"
                                    aria-label={`Edit ${saved.name}`}
                                    onPointerDown={(event) =>
                                        event.stopPropagation()
                                    }
                                    onClick={(event) => {
                                        event.stopPropagation();
                                        editSaved(saved);
                                    }}
                                    className="absolute right-2 inline-flex size-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                >
                                    <Pencil className="size-3.5" aria-hidden />
                                </button>
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
    const nameEmpty = mentionInputValue(activeMention.label).trim() === '';
    const emptyHandle = hasEmptyActiveHandle(activeMention, activePlatforms);

    return (
        <>
            <div className="flex items-center gap-2">
                <span
                    aria-hidden
                    className="flex w-[15px] shrink-0 justify-center text-sm font-medium text-muted-foreground"
                >
                    @
                </span>
                <InputGroup className="h-8 min-w-0 flex-1 rounded-lg">
                    <InputGroupInput
                        ref={mentionNameInputRef}
                        value={mentionInputValue(activeMention.label)}
                        placeholder="Mention name"
                        aria-label="Mention name shown in the post"
                        className="text-xs font-medium"
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
            <div className="h-px bg-border" />
            <div className="flex flex-col gap-1.5">
                {/*
                 * Keyed on the mention id so each field's local mode state resets
                 * when a different mention is edited.
                 */}
                {activePlatforms.map((platform) =>
                    platform === 'linkedin' ? (
                        <LinkedInMentionField
                            key={`linkedin-${activeMention.id}`}
                            activeMention={activeMention}
                            onUpdateMention={onUpdateMention}
                        />
                    ) : (
                        <PlatformMentionField
                            key={`${platform}-${activeMention.id}`}
                            activeMention={activeMention}
                            platform={platform}
                            onUpdateMention={onUpdateMention}
                        />
                    ),
                )}
            </div>
            {onSave && (
                <div className="flex flex-col gap-1.5">
                    {emptyHandle && !nameEmpty && !saveMentionProcessing && (
                        <p className="text-[11px] text-muted-foreground">
                            Fill in every platform to save this mention — the
                            empty one still works in this post.
                        </p>
                    )}
                    <button
                        type="button"
                        disabled={
                            saveMentionProcessing || nameEmpty || emptyHandle
                        }
                        onClick={onSave}
                        className={cn(
                            'rounded-lg bg-primary py-1.5 text-xs font-medium text-primary-foreground transition-colors hover:bg-primary/90',
                            'disabled:cursor-not-allowed disabled:opacity-60',
                        )}
                    >
                        {saveMentionProcessing
                            ? 'Saving…'
                            : 'Save to workspace'}
                    </button>
                </div>
            )}
        </>
    );
}

type PlatformMentionFieldProps = {
    activeMention: MentionPlaceholder;
    platform: PlatformName;
    onUpdateMention: (
        previous: MentionPlaceholder,
        next: MentionPlaceholder,
    ) => void;
};

/**
 * One non-LinkedIn platform row. The mention/plain-text mode is held in local
 * state rather than re-derived from whether the stored handle currently starts
 * with '@'. That keeps the toggle, the '@' prefix, and the placeholder in
 * agreement — and, crucially, clearing the field no longer silently flips the
 * mode. Platforms that can't carry an `@` mention (only Facebook, today) show a
 * static "Plain text" label instead of a toggle, so every row states its mode.
 * The parent keys this on the mention id so the mode resets per mention.
 */
function PlatformMentionField({
    activeMention,
    platform,
    onUpdateMention,
}: PlatformMentionFieldProps) {
    const supportsMention = platformSupportsMention(platform);
    const [useMention, setUseMention] = useState(() =>
        usesPlatformMention(activeMention, platform),
    );
    const handleValue = activeMention.handles[platform] ?? activeMention.label;

    function setMode(next: boolean) {
        setUseMention(next);
        onUpdateMention(
            activeMention,
            setPlatformMentionMode(activeMention, platform, next),
        );
    }

    return (
        <div className="flex items-center gap-2">
            <PlatformGlyph
                platform={platform}
                size={15}
                className="shrink-0 text-muted-foreground"
            />
            <InputGroup className="h-8 min-w-0 flex-1 rounded-lg">
                {supportsMention && useMention && (
                    <InputGroupAddon className="pr-0 text-muted-foreground">
                        @
                    </InputGroupAddon>
                )}
                <InputGroupInput
                    value={mentionInputValue(handleValue)}
                    placeholder={useMention ? 'handle' : 'display name'}
                    aria-label={`${platform} ${
                        useMention ? 'handle' : 'display text'
                    } for ${activeMention.label}`}
                    className="text-xs"
                    onChange={(event) =>
                        onUpdateMention(
                            activeMention,
                            updateMentionHandle(
                                activeMention,
                                platform,
                                event.target.value,
                                supportsMention && useMention,
                            ),
                        )
                    }
                />
            </InputGroup>
            {supportsMention ? (
                <MentionModeToggle
                    ariaLabel={`How to show ${activeMention.label} on ${platform}`}
                    value={useMention ? 'mention' : 'text'}
                    options={[
                        { value: 'mention', label: 'Mention' },
                        { value: 'text', label: 'Plain text' },
                    ]}
                    onChange={(next) => setMode(next === 'mention')}
                />
            ) : (
                <span className="shrink-0 px-1 text-[10px] font-medium text-muted-foreground/70">
                    Plain text
                </span>
            )}
        </div>
    );
}

type LinkedInMentionFieldProps = {
    activeMention: MentionPlaceholder;
    onUpdateMention: (
        previous: MentionPlaceholder,
        next: MentionPlaceholder,
    ) => void;
};

/**
 * The LinkedIn display-name field with a plain-text ⇄ tag toggle. In tag mode a
 * pasted company URL / org URN is auto-detected: the URN is routed to
 * `linkedin_urn` and stripped from the field, leaving only the display name.
 * Local state (pre-URN toggle intent + vanity hint) resets per mention because
 * the parent keys this element on the mention id.
 */
function LinkedInMentionField({
    activeMention,
    onUpdateMention,
}: LinkedInMentionFieldProps) {
    const [tagIntent, setTagIntent] = useState(false);
    const [vanityHint, setVanityHint] = useState<string | null>(null);
    const urn = activeMention.handles.linkedin_urn;
    const tagMode = Boolean(urn) || tagIntent;
    const displayValue = mentionInputValue(
        activeMention.handles.linkedin ?? activeMention.label,
    );

    function handleChange(value: string) {
        if (!tagMode) {
            onUpdateMention(
                activeMention,
                updateMentionHandle(activeMention, 'linkedin', value, false),
            );

            return;
        }

        const { urn: foundUrn, vanity, rest } = extractLinkedInOrgRef(value);
        let next = updateMentionHandle(activeMention, 'linkedin', rest, false);
        if (foundUrn) {
            next = updateMentionLinkedInUrn(next, foundUrn);
        }

        setVanityHint(foundUrn ? null : vanity);
        onUpdateMention(activeMention, next);
    }

    function setTagMode(next: boolean) {
        if (!next) {
            setTagIntent(false);
            setVanityHint(null);
            onUpdateMention(
                activeMention,
                updateMentionLinkedInUrn(activeMention, ''),
            );

            return;
        }

        setTagIntent(true);
    }

    function removeUrn() {
        setVanityHint(null);
        onUpdateMention(
            activeMention,
            updateMentionLinkedInUrn(activeMention, ''),
        );
    }

    return (
        <div className="flex flex-col gap-1 text-xs">
            <div className="flex items-center gap-2">
                <PlatformGlyph
                    platform="linkedin"
                    size={15}
                    className="shrink-0 text-muted-foreground"
                />
                <InputGroup className="h-8 min-w-0 flex-1 rounded-lg">
                    <InputGroupInput
                        value={displayValue}
                        placeholder={
                            tagMode ? 'Company name' : 'Name shown on LinkedIn'
                        }
                        aria-label={`linkedin ${
                            tagMode ? 'company name' : 'display text'
                        } for ${activeMention.label}`}
                        className="text-xs"
                        onChange={(event) => handleChange(event.target.value)}
                    />
                </InputGroup>
                <MentionModeToggle
                    ariaLabel={`How to show ${activeMention.label} on LinkedIn`}
                    value={tagMode ? 'tag' : 'text'}
                    options={[
                        { value: 'text', label: 'Plain text' },
                        { value: 'tag', label: 'Tag company' },
                    ]}
                    onChange={(next) => setTagMode(next === 'tag')}
                />
            </div>
            {tagMode && (
                <div className="flex flex-col gap-1 pl-6">
                    {urn ? (
                        <span className="flex items-center gap-1.5 rounded-md bg-muted/60 px-2 py-1">
                            <Building2 className="size-3.5 shrink-0 text-primary" />
                            <span className="text-foreground">
                                Company linked
                            </span>
                            <code className="min-w-0 flex-1 truncate font-mono text-[11px] text-muted-foreground">
                                {urn}
                            </code>
                            <button
                                type="button"
                                onClick={removeUrn}
                                aria-label="Remove company tag"
                                className="inline-flex size-4 shrink-0 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-muted-foreground/15 hover:text-foreground"
                            >
                                <X className="size-3" />
                            </button>
                        </span>
                    ) : vanityHint ? (
                        <span className="flex items-start gap-1.5 text-[11px] text-muted-foreground">
                            <Info className="mt-px size-3.5 shrink-0" />
                            <span>
                                That link doesn&rsquo;t include the company ID.
                                Paste the URL with its number, or the
                                company&rsquo;s URN.
                            </span>
                        </span>
                    ) : (
                        <span className="text-[11px] text-muted-foreground">
                            Paste the company&rsquo;s LinkedIn page URL to tag
                            them.
                        </span>
                    )}
                    {urn && (
                        <span className="text-[11px] text-muted-foreground/80">
                            Match the company&rsquo;s exact name above, or
                            LinkedIn won&rsquo;t link it.
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

type MentionModeToggleProps = {
    /** The currently selected option value. */
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
    ariaLabel: string;
};

/**
 * Compact segmented control that shows both mode options side by side with the
 * active one highlighted — so it is always clear which mode is on, unlike a
 * single button whose label names the mode it would switch to.
 */
function MentionModeToggle({
    value,
    options,
    onChange,
    ariaLabel,
}: MentionModeToggleProps) {
    return (
        <div
            role="group"
            aria-label={ariaLabel}
            className="inline-flex shrink-0 items-center rounded-md bg-muted p-0.5"
        >
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'rounded-[5px] px-1.5 py-1 text-[11px] leading-none font-medium whitespace-nowrap transition-colors',
                            active
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
