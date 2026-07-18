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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import type { LegalPageType, LegalSettings } from '@/types/legal';

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
            <span className="min-w-0 flex-1 truncate font-mono text-xs text-muted-foreground">
                {url}
            </span>
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
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                className="shrink-0"
                aria-label="Open page in a new tab"
                render={
                    <a href={url} target="_blank" rel="noopener noreferrer" />
                }
            >
                <ExternalLink className="size-4" />
            </Button>
        </div>
    );
}

/**
 * One document editor: publish toggle, Markdown source, validation error, and —
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

    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>
                    Written in Markdown and rendered on your public{' '}
                    <span className="font-mono text-xs">/{type}</span> page.
                </CardDescription>
                <CardAction>
                    <Label htmlFor={switchId} className="gap-2">
                        <span className="text-xs text-muted-foreground">
                            {published ? 'Published' : 'Draft'}
                        </span>
                        <Switch
                            id={switchId}
                            checked={published}
                            onCheckedChange={onPublishedChange}
                            disabled={disabled}
                        />
                    </Label>
                </CardAction>
            </CardHeader>
            <CardContent className="space-y-2">
                <Label htmlFor={bodyId} className="sr-only">
                    {title} content
                </Label>
                <Textarea
                    id={bodyId}
                    value={body}
                    onChange={(event) => onBodyChange(event.target.value)}
                    disabled={disabled}
                    aria-invalid={!!error}
                    placeholder={`# ${title}\n\nDescribe your ${title.toLowerCase()} here. Markdown headings, lists, links, and emphasis are supported.`}
                    className="min-h-56 font-mono text-xs leading-relaxed"
                />
                <p className="text-xs text-muted-foreground">
                    Markdown is supported.
                </p>
                <InputError message={error} />
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
        form.put(LegalPagesController.update().url, { preserveScroll: true });
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

    const slugPreview = form.data.slug || 'your-slug';

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
