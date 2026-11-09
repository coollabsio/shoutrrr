import { Popover as PopoverPrimitive } from '@base-ui/react/popover';
import { useState } from 'react';

import { Users, X } from '@/components/ui/icons';
import {
    MAX_TAGGED_PEOPLE,
    parseTaggedHandle,
} from '@/lib/compose/tagged-people';
import { cn } from '@/lib/utils';

type Props = {
    handles: string[];
    onChange: (handles: string[]) => void;
};

/**
 * Tag people in an X post's photos: a toolbar button opening a handle list.
 * Handles are only stored here; the X user ids are looked up once at publish.
 */
export function TagPeoplePopover({ handles, onChange }: Props) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState('');
    const full = handles.length >= MAX_TAGGED_PEOPLE;
    const invalid = draft.trim() !== '' && parseTaggedHandle(draft) === null;

    function add() {
        const handle = parseTaggedHandle(draft);
        if (
            handle === null ||
            full ||
            handles.some((h) => h.toLowerCase() === handle.toLowerCase())
        ) {
            return;
        }
        onChange([...handles, handle]);
        setDraft('');
    }

    return (
        <PopoverPrimitive.Root open={open} onOpenChange={setOpen}>
            <PopoverPrimitive.Trigger
                render={
                    <button
                        type="button"
                        title="Tag people in this post's photos"
                        data-active={open || handles.length > 0}
                        className={cn(
                            'inline-flex h-8 items-center gap-1.5 rounded-md border border-transparent bg-transparent px-2.5 text-[12px] text-muted-foreground transition-colors sm:h-7',
                            'hover:border-border hover:bg-background hover:text-foreground',
                            'data-[active=true]:border-border data-[active=true]:bg-background data-[active=true]:text-foreground data-[active=true]:shadow-[0_1px_2px_0_rgb(0_0_0/0.04)]',
                        )}
                    />
                }
            >
                <Users className="size-3.5" aria-hidden="true" />
                <span>
                    {handles.length > 0
                        ? `Tagged: ${handles.length}`
                        : 'Tag people'}
                </span>
            </PopoverPrimitive.Trigger>
            <PopoverPrimitive.Portal>
                <PopoverPrimitive.Positioner
                    align="end"
                    side="top"
                    sideOffset={8}
                    className="isolate z-50"
                >
                    <PopoverPrimitive.Popup
                        className={cn(
                            'z-50 w-72 overflow-hidden rounded-2xl bg-popover text-popover-foreground shadow-lg ring-1 ring-foreground/5 outline-hidden dark:ring-foreground/10',
                            'origin-(--transform-origin) duration-100 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95',
                        )}
                    >
                        <div className="px-4 pt-3 pb-2">
                            <p className="text-[13px] font-medium">
                                Tag people
                            </p>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Shown on the post&apos;s photos. Up to{' '}
                                {MAX_TAGGED_PEOPLE}.
                            </p>
                        </div>
                        <div className="px-4 pb-3">
                            <input
                                value={draft}
                                onChange={(e) => setDraft(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ',') {
                                        e.preventDefault();
                                        add();
                                    }
                                }}
                                onBlur={add}
                                disabled={full}
                                aria-label="X handle to tag"
                                aria-invalid={invalid}
                                placeholder={full ? 'Limit reached' : '@handle'}
                                className="h-8 w-full rounded-lg border border-transparent bg-input/50 px-2.5 text-[13px] outline-none placeholder:text-muted-foreground focus-visible:border-ring disabled:opacity-50 aria-invalid:border-destructive"
                            />
                            {handles.length > 0 && (
                                <ul className="mt-2 flex flex-wrap gap-1">
                                    {handles.map((handle) => (
                                        <li
                                            key={handle}
                                            className="inline-flex items-center gap-1 rounded-md bg-muted py-0.5 pr-1 pl-2 text-xs"
                                        >
                                            @{handle}
                                            <button
                                                type="button"
                                                aria-label={`Remove @${handle}`}
                                                onClick={() =>
                                                    onChange(
                                                        handles.filter(
                                                            (h) => h !== handle,
                                                        ),
                                                    )
                                                }
                                                className="rounded p-0.5 text-muted-foreground hover:text-foreground"
                                            >
                                                <X
                                                    className="size-3"
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </PopoverPrimitive.Popup>
                </PopoverPrimitive.Positioner>
            </PopoverPrimitive.Portal>
        </PopoverPrimitive.Root>
    );
}

export default TagPeoplePopover;
