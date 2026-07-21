import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import PostShareController from '@/actions/App/Http/Controllers/Posts/PostShareController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dayjs } from '@/lib/datetime/dayjs';

type Props = {
    postId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

type Preset = 'never' | '1d' | '7d' | '30d' | 'custom';

const EXPIRY_ITEMS: { value: Preset; label: string }[] = [
    { value: 'never', label: 'Never' },
    { value: '1d', label: 'In 24 hours' },
    { value: '7d', label: 'In 7 days' },
    { value: '30d', label: 'In 30 days' },
    { value: 'custom', label: 'Custom…' },
];

type ShareItem = {
    id: string;
    expires_at: string | null;
    created_at: string;
};

type CreatedShare = {
    id: string;
    url: string;
    expires_at: string | null;
};

type DurationSpec = [number, 'day'];

function resolveExpiresAt(preset: Preset, customLocal: string): string | null {
    if (preset === 'never') {
        return null;
    }
    if (preset === 'custom') {
        const parsed = dayjs(customLocal);
        return parsed.isValid() ? parsed.toISOString() : null;
    }
    const amounts: Record<Exclude<Preset, 'never' | 'custom'>, DurationSpec> = {
        '1d': [1, 'day'],
        '7d': [7, 'day'],
        '30d': [30, 'day'],
    };
    const [amount, unit] = amounts[preset];
    return dayjs().add(amount, unit).toISOString();
}

export function ShareDialog({ postId, open, onOpenChange }: Props) {
    const [preset, setPreset] = useState<Preset>('never');
    const [customLocal, setCustomLocal] = useState('');
    const [minted, setMinted] = useState<CreatedShare | null>(null);
    const [activeShares, setActiveShares] = useState<ShareItem[]>([]);
    const [loadingList, setLoadingList] = useState(false);
    const [creating, setCreating] = useState(false);
    const [revokingId, setRevokingId] = useState<string | null>(null);

    const http = useHttp<Record<string, never>, Record<string, never>>({});

    async function fetchActiveShares() {
        setLoadingList(true);
        try {
            const result = await http.get(
                PostShareController.index(postId).url,
                { onNetworkError: () => undefined },
            );
            setActiveShares(result as unknown as ShareItem[]);
        } finally {
            setLoadingList(false);
        }
    }

    useEffect(() => {
        if (open) {
            setMinted(null);
            void fetchActiveShares();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    async function handleCreate() {
        const expiresAt = resolveExpiresAt(preset, customLocal);
        setCreating(true);
        try {
            http.transform(() => ({ expires_at: expiresAt }));
            const result = await http.post(
                PostShareController.store(postId).url,
                { onNetworkError: () => undefined },
            );
            setMinted(result as unknown as CreatedShare);
            void fetchActiveShares();
        } finally {
            setCreating(false);
        }
    }

    async function handleRevoke(shareId: string) {
        setRevokingId(shareId);
        try {
            http.transform(() => ({}));
            await http.delete(
                PostShareController.destroy({ post: postId, share: shareId })
                    .url,
                { onNetworkError: () => undefined },
            );
            void fetchActiveShares();
        } finally {
            setRevokingId(null);
        }
    }

    function copyLink(url: string) {
        void navigator.clipboard?.writeText(url);
        toast.success('Link copied');
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Share a private link</DialogTitle>
                    <DialogDescription>
                        Anyone with the link can view this post&apos;s content.
                        The link is shown once.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex items-end gap-2">
                    <div className="flex-1">
                        <label
                            className="mb-1 block text-[12px] text-muted-foreground"
                            htmlFor="share-expiry"
                        >
                            Expires
                        </label>
                        <Select
                            items={EXPIRY_ITEMS}
                            value={preset}
                            onValueChange={(v) => setPreset(v as Preset)}
                        >
                            <SelectTrigger id="share-expiry">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {EXPIRY_ITEMS.map((item) => (
                                    <SelectItem
                                        key={item.value}
                                        value={item.value}
                                    >
                                        {item.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    {preset === 'custom' && (
                        <Input
                            type="datetime-local"
                            aria-label="Custom expiry date"
                            value={customLocal}
                            onChange={(e) => setCustomLocal(e.target.value)}
                            className="flex-1"
                        />
                    )}
                    <Button
                        onClick={() => void handleCreate()}
                        disabled={creating}
                    >
                        Create link
                    </Button>
                </div>

                {minted && (
                    <div className="flex items-center gap-2">
                        <Input
                            readOnly
                            value={minted.url}
                            aria-label="Share link"
                        />
                        <Button
                            variant="outline"
                            onClick={() => copyLink(minted.url)}
                        >
                            Copy
                        </Button>
                    </div>
                )}

                <div className="space-y-1">
                    <p className="text-[12px] font-medium text-muted-foreground">
                        Active links
                    </p>
                    {loadingList ? (
                        <p className="text-[12px] text-muted-foreground">
                            Loading…
                        </p>
                    ) : activeShares.length === 0 ? (
                        <p className="text-[12px] text-muted-foreground">
                            No active links.
                        </p>
                    ) : (
                        activeShares.map((share) => (
                            <div
                                key={share.id}
                                className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-[12.5px]"
                            >
                                <span className="text-muted-foreground">
                                    {share.expires_at
                                        ? `Expires ${dayjs(share.expires_at).format('MMM D, YYYY')}`
                                        : 'Never expires'}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => void handleRevoke(share.id)}
                                    disabled={revokingId === share.id}
                                >
                                    Revoke
                                </Button>
                            </div>
                        ))
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
