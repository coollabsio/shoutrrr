import { Head, useForm } from '@inertiajs/react';
import { Check, Copy, ExternalLink } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';

import LegalPagesController from '@/actions/App/Http/Controllers/Settings/LegalPagesController';
import WorkspaceSettingsController from '@/actions/App/Http/Controllers/Settings/WorkspaceSettingsController';
import Heading from '@/components/common/heading';
import InputError from '@/components/common/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { RichTextEditor } from '@/components/ui/rich-text-editor';
import { Switch } from '@/components/ui/switch';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import type { LegalPageType, LegalSettings } from '@/types/legal';

/** Whether a rich-text body has any visible text (SSR-safe). */
function documentHasText(html: string): boolean {
    const text =
        typeof document !== 'undefined'
            ? (Object.assign(document.createElement('div'), { innerHTML: html })
                  .textContent ?? '')
            : html.replace(/<[^>]*>/g, '');

    // JS \s matches regular and non-breaking whitespace, so \S is any real text.
    return /\S/.test(text);
}

type Props = {
    legal: LegalSettings;
};

/**
 * Read-only row that surfaces a live public URL for a published document, with
 * copy-to-clipboard and an open-in-new-tab affordance.
 */
function PublicUrlRow({ url }: { url: string }) {
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);

    async function handleCopy() {
        const ok = await copy(url);

        if (!ok) {
            toast.error('Copy failed — copy the URL manually.');

            return;
        }

        setCopied(true);
        toast.success('URL copied');
        setTimeout(() => setCopied(false), 2000);
    }

    return (
        <div className="flex items-center gap-1 rounded-2xl border border-border bg-muted/30 px-3 py-1.5">
            {/* The URL itself is the link — one tap opens the live page in a new tab. */}
            <a
                href={url}
                target="_blank"
                rel="noopener noreferrer"
                title="Open the live page in a new tab"
                className="group inline-flex min-w-0 flex-1 items-center gap-1.5 font-mono text-xs text-primary hover:underline"
            >
                <span className="truncate">{url}</span>
                <ExternalLink className="size-3.5 shrink-0 opacity-70 transition-opacity group-hover:opacity-100" />
            </a>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="shrink-0"
                onClick={handleCopy}
            >
                {copied ? (
                    <Check className="size-4" />
                ) : (
                    <Copy className="size-4" />
                )}
                {copied ? 'Copied' : 'Copy'}
            </Button>
        </div>
    );
}

/**
 * One document editor: publish toggle, rich-text body, validation error, and —
 * once the saved state has it published under a slug — its live public URL.
 */
function LegalDocumentCard({
    type,
    title,
    body,
    onBodyChange,
    published,
    onPublishedChange,
    error,
    publicUrl,
    disabled,
}: {
    type: LegalPageType;
    title: string;
    body: string;
    onBodyChange: (value: string) => void;
    published: boolean;
    onPublishedChange: (value: boolean) => void;
    error?: string;
    publicUrl: string | null;
    disabled: boolean;
}) {
    const bodyId = `${type}-body`;
    const switchId = `${type}-published`;
    const hasContent = documentHasText(body);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>
                    Rendered on your public{' '}
                    <span className="font-mono text-xs">/{type}</span> page.
                    Paste from Google Docs, Word, or a PDF — formatting is
                    preserved.
                </CardDescription>
                <CardAction>
                    <span
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium',
                            published
                                ? 'border-primary/30 bg-primary/10 text-primary'
                                : 'border-border bg-muted text-muted-foreground',
                        )}
                    >
                        <span
                            className={cn(
                                'size-1.5 rounded-full',
                                published
                                    ? 'bg-primary'
                                    : 'bg-muted-foreground/50',
                            )}
                        />
                        {published ? 'Public' : 'Draft'}
                    </span>
                </CardAction>
            </CardHeader>
            <CardContent className="space-y-3">
                <RichTextEditor
                    id={bodyId}
                    ariaLabel={`${title} content`}
                    value={body}
                    onChange={onBodyChange}
                    disabled={disabled}
                    aria-invalid={!!error}
                    placeholder={`Describe your ${title.toLowerCase()} here, or paste it in.`}
                />
                <InputError message={error} />

                {/*
                 * Publishing is an explicit, clearly-labeled action rather than a
                 * bare switch, so it is obvious the page stays private until it is
                 * turned on. It is disabled until there is content to publish.
                 */}
                <div className="flex items-center justify-between gap-4 rounded-2xl border border-border bg-muted/20 px-4 py-3">
                    <div className="space-y-0.5">
                        <Label htmlFor={switchId} className="font-medium">
                            Publish this page
                        </Label>
                        <p className="text-xs text-muted-foreground">
                            {hasContent
                                ? 'Turn on to make it publicly visible. Off keeps it a private draft.'
                                : 'Add content above, then turn this on to publish.'}
                        </p>
                    </div>
                    <Switch
                        id={switchId}
                        checked={published}
                        onCheckedChange={onPublishedChange}
                        disabled={disabled || (!hasContent && !published)}
                    />
                </div>

                {publicUrl && <PublicUrlRow url={publicUrl} />}
            </CardContent>
        </Card>
    );
}

export default function LegalPages({ legal }: Props) {
    // Origin is resolved after mount to keep SSR and client markup identical.
    const [origin, setOrigin] = useState('');

    useEffect(() => {
        setOrigin(window.location.origin);
    }, []);

    const form = useForm({
        slug: legal.slug ?? '',
        terms_body: legal.terms.body ?? '',
        privacy_body: legal.privacy.body ?? '',
        terms_published: legal.terms.published,
        privacy_published: legal.privacy.published,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(LegalPagesController.update().url, {
            preserveScroll: true,
            // Mirror the success toast on failure: surface the first validation
            // message (e.g. a slug already in use) so the error is not missed.
            onError: (errors) => {
                const [firstError] = Object.values(errors);
                toast.error(
                    firstError ??
                        'Couldn’t save your legal pages. Please try again.',
                );
            },
        });
    }

    // Live URLs reflect the last saved state (the `legal` prop), so a link is
    // only shown once the document is actually reachable.
    const savedSlug = legal.slug ?? '';
    const termsUrl =
        origin && savedSlug && legal.terms.published
            ? `${origin}/${savedSlug}/terms`
            : null;
    const privacyUrl =
        origin && savedSlug && legal.privacy.published
            ? `${origin}/${savedSlug}/privacy`
            : null;

    // Preview the normalized slug (the server lowercases it on save).
    const slugPreview = form.data.slug.toLowerCase() || 'your-slug';

    return (
        <>
            <Head title="Legal pages" />

            <form onSubmit={submit} className="space-y-6">
                <Heading
                    variant="small"
                    title="Legal pages"
                    description="Publish public Terms of Service and Privacy Policy pages. Social platforms such as X, Meta, and LinkedIn require these URLs when you apply for developer or API access."
                />

                <div className="grid gap-2">
                    <Label htmlFor="slug">Public URL slug</Label>
                    <InputGroup>
                        <InputGroupAddon>
                            <InputGroupText className="font-mono text-xs">
                                {origin ? `${origin}/` : '/'}
                            </InputGroupText>
                        </InputGroupAddon>
                        <InputGroupInput
                            id="slug"
                            name="slug"
                            value={form.data.slug}
                            onChange={(event) =>
                                form.setData('slug', event.target.value)
                            }
                            disabled={form.processing}
                            autoCapitalize="none"
                            autoCorrect="off"
                            spellCheck={false}
                            aria-invalid={!!form.errors.slug}
                            placeholder="acme"
                            className="font-mono text-xs"
                        />
                        <InputGroupAddon align="inline-end">
                            <InputGroupText className="font-mono text-xs">
                                /terms · /privacy
                            </InputGroupText>
                        </InputGroupAddon>
                    </InputGroup>
                    <p className="text-xs text-muted-foreground">
                        Lowercase letters, numbers, and hyphens. Your pages live
                        at{' '}
                        <span className="font-mono">
                            {origin}/{slugPreview}/terms
                        </span>{' '}
                        and{' '}
                        <span className="font-mono">
                            {origin}/{slugPreview}/privacy
                        </span>
                        .
                    </p>
                    <InputError message={form.errors.slug} />
                </div>

                <LegalDocumentCard
                    type="terms"
                    title="Terms of Service"
                    body={form.data.terms_body}
                    onBodyChange={(value) => form.setData('terms_body', value)}
                    published={form.data.terms_published}
                    onPublishedChange={(value) =>
                        form.setData('terms_published', value)
                    }
                    error={form.errors.terms_body}
                    publicUrl={termsUrl}
                    disabled={form.processing}
                />

                <LegalDocumentCard
                    type="privacy"
                    title="Privacy Policy"
                    body={form.data.privacy_body}
                    onBodyChange={(value) =>
                        form.setData('privacy_body', value)
                    }
                    published={form.data.privacy_published}
                    onPublishedChange={(value) =>
                        form.setData('privacy_published', value)
                    }
                    error={form.errors.privacy_body}
                    publicUrl={privacyUrl}
                    disabled={form.processing}
                />

                <div className="flex items-center gap-4">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? 'Saving...' : 'Save'}
                    </Button>
                    {form.recentlySuccessful && (
                        <p className="text-sm text-muted-foreground">Saved</p>
                    )}
                </div>
            </form>
        </>
    );
}

LegalPages.layout = {
    breadcrumbs: [
        {
            title: 'Workspace settings',
            href: WorkspaceSettingsController.showOverview().url,
        },
        {
            title: 'Legal pages',
            href: LegalPagesController.edit().url,
        },
    ],
};
