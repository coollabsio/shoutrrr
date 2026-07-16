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
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    extractLinkedInOrgRef,
    mentionInputValue,
    normalizeMentionName,
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
                    if (platform === 'linkedin') {
                        // Keyed on the mention id so the local tag-mode/vanity
                        // state resets when a different mention is edited.
                        return (
                            <LinkedInMentionField
                                key={`linkedin-${activeMention.id}`}
                                activeMention={activeMention}
                                onUpdateMention={onUpdateMention}
                            />
                        );
                    }

                    const useMention = usesPlatformMention(
                        activeMention,
                        platform,
                    );
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
                            <InputGroup className="min-h-9 w-full min-w-0 rounded-lg border-border bg-background">
                                {useMention && (
                                    <InputGroupAddon className="pr-0 text-foreground">
                                        @
                                    </InputGroupAddon>
                                )}
                                <InputGroupInput
                                    value={mentionInputValue(handleValue)}
                                    placeholder={
                                        useMention ? 'handle' : 'display name'
                                    }
                                    aria-label={`${platform} ${
                                        useMention ? 'handle' : 'display text'
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
                                <InputGroupAddon align="inline-end">
                                    <InputGroupButton
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
                                            ? 'Plain text'
                                            : '@ mention'}
                                    </InputGroupButton>
                                </InputGroupAddon>
                            </InputGroup>
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

    function toggleMode() {
        if (tagMode) {
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
        <label className="flex flex-col gap-1.5 text-xs">
            <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                <PlatformGlyph
                    platform="linkedin"
                    size={14}
                    className="text-foreground"
                />
                <span className="capitalize">linkedin</span>
            </span>
            <InputGroup className="min-h-9 w-full min-w-0 rounded-lg border-border bg-background">
                <InputGroupInput
                    value={displayValue}
                    placeholder={
                        tagMode ? 'Company name' : 'Name shown on LinkedIn'
                    }
                    aria-label={`linkedin ${
                        tagMode ? 'company name' : 'display text'
                    } for ${activeMention.label}`}
                    onChange={(event) => handleChange(event.target.value)}
                />
                <InputGroupAddon align="inline-end">
                    <InputGroupButton onClick={toggleMode}>
                        {tagMode ? 'Plain text' : 'Tag company'}
                    </InputGroupButton>
                </InputGroupAddon>
            </InputGroup>
            {tagMode &&
                (urn ? (
                    <span className="flex items-center gap-1.5 rounded-lg bg-muted/60 px-2 py-1.5">
                        <Building2 className="size-3.5 shrink-0 text-primary" />
                        <span className="text-foreground">Company linked</span>
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
                    <span className="flex items-start gap-1.5 text-muted-foreground">
                        <Info className="mt-px size-3.5 shrink-0" />
                        <span>
                            That link doesn&rsquo;t include the company ID.
                            Paste the URL with its number, or the
                            company&rsquo;s URN.
                        </span>
                    </span>
                ) : (
                    <span className="text-muted-foreground">
                        Paste the company&rsquo;s LinkedIn page URL to tag them.
                    </span>
                ))}
            {tagMode && urn && (
                <span className="text-[11px] text-muted-foreground/80">
                    Match the company&rsquo;s exact name above, or LinkedIn
                    won&rsquo;t link it.
                </span>
            )}
        </label>
    );
}
