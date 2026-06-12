import { EditorContent, useEditor } from '@tiptap/react';
import { Split } from 'lucide-react';
import { useEffect } from 'react';

import { cn } from '@/lib/utils';

import { baseTextToDoc, docToBaseText, type DocNode } from './tiptap-doc';
import { composerExtensions } from './tiptap/setup';
import type { PlatformName } from './types';

type EditorBodyProps = {
    value: string;
    onChange: (text: string) => void;
    onBlur: () => void;
    placeholder?: string;
    /** When true, render the ring-tinted override banner above the editor. */
    overrideBanner?: boolean;
    /** Human label of the active platform for the override banner copy. */
    activePlatformLabel?: string | null;
    /** Reset-to-base handler for the override banner. */
    onResetOverride?: () => void;
    /**
     * Active platform + splitting config pushed into the section-markers plugin
     * whenever the active tab changes. Omit to leave markers at their defaults.
     */
    markerState?: {
        platform: PlatformName;
        autoSplit: boolean;
        limit: number;
        threadMax: number | null;
    };
};

export default function EditorBody({
    value,
    onChange,
    onBlur,
    placeholder,
    overrideBanner = false,
    activePlatformLabel,
    onResetOverride,
    markerState,
}: EditorBodyProps) {
    const editor = useEditor({
        extensions: composerExtensions({ placeholder }),
        content: baseTextToDoc(value) as object,
        onUpdate: ({ editor }) =>
            onChange(docToBaseText(editor.getJSON() as DocNode)),
        onBlur,
    });

    // Keep the editor in sync when the value is replaced externally (tab switch,
    // conflict resolution) without emitting an update.
    useEffect(() => {
        if (!editor) {
            return;
        }
        const current = docToBaseText(editor.getJSON() as DocNode);
        if (current !== value) {
            editor.commands.setContent(baseTextToDoc(value) as object, {
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
            <div className="px-4 pt-[22px] pb-[18px] sm:px-[26px]">
                <EditorContent
                    editor={editor}
                    className="max-w-none text-[16px] leading-[1.65] tracking-[-0.005em] text-foreground focus:outline-none [&_.ProseMirror]:outline-none"
                />
            </div>
        </div>
    );
}
