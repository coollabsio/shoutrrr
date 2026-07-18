import { Placeholder } from '@tiptap/extensions';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    Bold,
    Italic,
    Link2,
    Link2Off,
    List,
    ListOrdered,
    Quote,
    Redo2,
    Underline as UnderlineIcon,
    Undo2,
} from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { Toggle } from '@/components/ui/toggle';
import { cn } from '@/lib/utils';

type RichTextEditorProps = {
    value: string;
    onChange: (html: string) => void;
    disabled?: boolean;
    id?: string;
    placeholder?: string;
    /** Accessible name for the editing surface. */
    ariaLabel?: string;
    'aria-invalid'?: boolean;
};

/** Only these schemes may be linked; the server enforces the same allowlist. */
function normalizeHref(raw: string): string | null {
    const href = raw.trim();

    if (href === '') {
        return null;
    }

    // Default a bare domain to https so "example.com" becomes a valid link.
    const candidate = /^(https?:|mailto:)/i.test(href) ? href : `https://${href}`;

    try {
        const url = new URL(candidate);

        return ['http:', 'https:', 'mailto:'].includes(url.protocol)
            ? candidate
            : null;
    } catch {
        return null;
    }
}

/**
 * A small, reusable rich-text editor built on the TipTap instance the project
 * already ships (the posts composer uses the same engine). It emits sanitized-
 * friendly HTML — headings, emphasis, lists, quotes and links — which pastes
 * cleanly from Google Docs / Word / PDFs. The server re-sanitizes on save, so
 * this component is purely a UX layer, never a trust boundary.
 */
export function RichTextEditor({
    value,
    onChange,
    disabled = false,
    id,
    placeholder,
    ariaLabel,
    'aria-invalid': ariaInvalid,
}: RichTextEditorProps) {
    const editor = useEditor({
        // Defer first render to the client so SSR markup and the hydrated editor
        // match (Inertia SSR is enabled).
        immediatelyRender: false,
        editable: !disabled,
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3] },
                link: {
                    openOnClick: false,
                    autolink: true,
                    HTMLAttributes: { rel: 'nofollow noopener noreferrer' },
                },
            }),
            Placeholder.configure({
                placeholder: placeholder ?? 'Write or paste your document…',
            }),
        ],
        content: value,
        onUpdate: ({ editor }) => onChange(editor.getHTML()),
        editorProps: {
            attributes: {
                ...(id ? { id } : {}),
                ...(ariaLabel ? { 'aria-label': ariaLabel } : {}),
                ...(ariaInvalid ? { 'aria-invalid': 'true' } : {}),
                role: 'textbox',
                'aria-multiline': 'true',
            },
        },
    });

    const state = useEditorState({
        editor,
        selector: ({ editor }) => ({
            bold: editor?.isActive('bold') ?? false,
            italic: editor?.isActive('italic') ?? false,
            underline: editor?.isActive('underline') ?? false,
            h2: editor?.isActive('heading', { level: 2 }) ?? false,
            h3: editor?.isActive('heading', { level: 3 }) ?? false,
            bullet: editor?.isActive('bulletList') ?? false,
            ordered: editor?.isActive('orderedList') ?? false,
            quote: editor?.isActive('blockquote') ?? false,
            link: editor?.isActive('link') ?? false,
            canUndo: editor?.can().undo() ?? false,
            canRedo: editor?.can().redo() ?? false,
        }),
    });

    // Keep TipTap editable in sync if the disabled prop changes after mount.
    useEffect(() => {
        editor?.setEditable(!disabled);
    }, [editor, disabled]);

    const [linkOpen, setLinkOpen] = useState(false);
    const [linkUrl, setLinkUrl] = useState('');

    if (!editor) {
        return null;
    }

    function openLinkEditor() {
        setLinkUrl((editor?.getAttributes('link').href as string) ?? '');
        setLinkOpen(true);
    }

    function applyLink(event: FormEvent) {
        event.preventDefault();
        const href = normalizeHref(linkUrl);

        if (href === null) {
            editor?.chain().focus().extendMarkRange('link').unsetLink().run();
        } else {
            editor
                ?.chain()
                .focus()
                .extendMarkRange('link')
                .setLink({ href })
                .run();
        }

        setLinkOpen(false);
    }

    return (
        <div
            className={cn(
                'rounded-2xl border border-input bg-transparent transition-colors focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/30',
                ariaInvalid && 'border-destructive ring-destructive/20',
                disabled && 'pointer-events-none opacity-60',
            )}
        >
            <div
                className="flex flex-wrap items-center gap-0.5 border-b border-border/70 px-2 py-1.5"
                role="toolbar"
                aria-label="Formatting"
            >
                <ToolbarButton
                    label="Undo"
                    disabled={!state?.canUndo}
                    onClick={() => editor.chain().focus().undo().run()}
                >
                    <Undo2 className="size-4" />
                </ToolbarButton>
                <ToolbarButton
                    label="Redo"
                    disabled={!state?.canRedo}
                    onClick={() => editor.chain().focus().redo().run()}
                >
                    <Redo2 className="size-4" />
                </ToolbarButton>

                <ToolbarSeparator />

                <ToolbarToggle
                    label="Bold"
                    pressed={!!state?.bold}
                    onPressedChange={() =>
                        editor.chain().focus().toggleBold().run()
                    }
                >
                    <Bold className="size-4" />
                </ToolbarToggle>
                <ToolbarToggle
                    label="Italic"
                    pressed={!!state?.italic}
                    onPressedChange={() =>
                        editor.chain().focus().toggleItalic().run()
                    }
                >
                    <Italic className="size-4" />
                </ToolbarToggle>
                <ToolbarToggle
                    label="Underline"
                    pressed={!!state?.underline}
                    onPressedChange={() =>
                        editor.chain().focus().toggleUnderline().run()
                    }
                >
                    <UnderlineIcon className="size-4" />
                </ToolbarToggle>

                <ToolbarSeparator />

                <ToolbarToggle
                    label="Heading"
                    pressed={!!state?.h2}
                    onPressedChange={() =>
                        editor.chain().focus().toggleHeading({ level: 2 }).run()
                    }
                >
                    <span className="text-xs font-semibold">H2</span>
                </ToolbarToggle>
                <ToolbarToggle
                    label="Subheading"
                    pressed={!!state?.h3}
                    onPressedChange={() =>
                        editor.chain().focus().toggleHeading({ level: 3 }).run()
                    }
                >
                    <span className="text-xs font-semibold">H3</span>
                </ToolbarToggle>

                <ToolbarSeparator />

                <ToolbarToggle
                    label="Bulleted list"
                    pressed={!!state?.bullet}
                    onPressedChange={() =>
                        editor.chain().focus().toggleBulletList().run()
                    }
                >
                    <List className="size-4" />
                </ToolbarToggle>
                <ToolbarToggle
                    label="Numbered list"
                    pressed={!!state?.ordered}
                    onPressedChange={() =>
                        editor.chain().focus().toggleOrderedList().run()
                    }
                >
                    <ListOrdered className="size-4" />
                </ToolbarToggle>
                <ToolbarToggle
                    label="Quote"
                    pressed={!!state?.quote}
                    onPressedChange={() =>
                        editor.chain().focus().toggleBlockquote().run()
                    }
                >
                    <Quote className="size-4" />
                </ToolbarToggle>

                <ToolbarSeparator />

                <Popover open={linkOpen} onOpenChange={setLinkOpen}>
                    <PopoverTrigger
                        render={
                            <Toggle
                                size="sm"
                                aria-label="Add link"
                                pressed={!!state?.link}
                                onClick={openLinkEditor}
                            >
                                <Link2 className="size-4" />
                            </Toggle>
                        }
                    />
                    <PopoverContent align="start" className="w-72 space-y-2 p-3">
                        <form onSubmit={applyLink} className="flex gap-2">
                            <Input
                                type="url"
                                inputMode="url"
                                autoFocus
                                value={linkUrl}
                                onChange={(event) =>
                                    setLinkUrl(event.target.value)
                                }
                                placeholder="https://example.com"
                                className="h-8"
                            />
                            <Button type="submit" size="sm">
                                Apply
                            </Button>
                        </form>
                        {state?.link && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="w-full justify-start text-muted-foreground"
                                onClick={() => {
                                    editor
                                        .chain()
                                        .focus()
                                        .extendMarkRange('link')
                                        .unsetLink()
                                        .run();
                                    setLinkOpen(false);
                                }}
                            >
                                <Link2Off className="size-4" />
                                Remove link
                            </Button>
                        )}
                    </PopoverContent>
                </Popover>
            </div>

            <EditorContent
                editor={editor}
                className="legal-prose max-h-[28rem] overflow-y-auto px-4 py-3 text-sm [&_.ProseMirror]:min-h-40 [&_.ProseMirror]:outline-none"
            />
        </div>
    );
}

function ToolbarToggle({
    label,
    pressed,
    onPressedChange,
    children,
}: {
    label: string;
    pressed: boolean;
    onPressedChange: () => void;
    children: React.ReactNode;
}) {
    return (
        <Toggle
            size="sm"
            aria-label={label}
            pressed={pressed}
            onPressedChange={onPressedChange}
        >
            {children}
        </Toggle>
    );
}

function ToolbarButton({
    label,
    disabled,
    onClick,
    children,
}: {
    label: string;
    disabled?: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
        >
            {children}
        </Button>
    );
}

function ToolbarSeparator() {
    return (
        <Separator
            orientation="vertical"
            className="mx-1 !h-5 self-center"
        />
    );
}

export default RichTextEditor;
