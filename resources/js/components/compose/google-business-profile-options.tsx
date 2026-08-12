import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { GoogleBusinessProfileLocalPostOptions } from '@/types/compose';

const POST_TYPES = [
    ['standard', 'Standard'],
    ['event', 'Event'],
    ['offer', 'Offer'],
] as const;

const DEFAULT_OPTIONS: GoogleBusinessProfileLocalPostOptions = {
    local_post_type: 'standard',
};

const CTA_TYPES = [
    ['', 'No button'],
    ['BOOK', 'Book'],
    ['ORDER', 'Order online'],
    ['SHOP', 'Shop'],
    ['LEARN_MORE', 'Learn more'],
    ['SIGN_UP', 'Sign up'],
    ['CALL', 'Call now'],
] as const;

type Props = {
    value: GoogleBusinessProfileLocalPostOptions | undefined;
    disabled: boolean;
    onChange: (value: GoogleBusinessProfileLocalPostOptions) => void;
};

export function GoogleBusinessProfileOptions({
    value,
    disabled,
    onChange,
}: Props) {
    const options = value ?? DEFAULT_OPTIONS;
    const update = (next: Partial<GoogleBusinessProfileLocalPostOptions>) =>
        onChange({ ...options, ...next });
    const hasSchedule =
        options.local_post_type === 'event' ||
        options.local_post_type === 'offer';

    return (
        <section className="border-t border-border bg-muted/30 px-3 py-3 sm:px-[14px]">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 className="text-[13px] font-semibold">
                        Google Business Profile post
                    </h3>
                    <p className="text-[12px] text-muted-foreground">
                        Choose the local-post details for this location.
                    </p>
                </div>
                <div className="flex rounded-xl border bg-background p-0.5">
                    {POST_TYPES.map(([type, label]) => (
                        <button
                            key={type}
                            type="button"
                            disabled={disabled}
                            onClick={() => update({ local_post_type: type })}
                            className={cn(
                                'rounded-lg px-2.5 py-1 text-[12px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                                options.local_post_type === type
                                    ? 'bg-foreground text-background'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                {hasSchedule && (
                    <div className="space-y-1.5 sm:col-span-2">
                        <Label htmlFor="gbp-post-title">Title</Label>
                        <Input
                            id="gbp-post-title"
                            value={options.title ?? ''}
                            disabled={disabled}
                            onChange={(event) =>
                                update({ title: event.target.value })
                            }
                            placeholder={
                                options.local_post_type === 'event'
                                    ? 'Event title'
                                    : 'Offer title'
                            }
                        />
                    </div>
                )}
                <div className="space-y-1.5">
                    <Label htmlFor="gbp-language">Language</Label>
                    <Input
                        id="gbp-language"
                        value={options.language ?? ''}
                        disabled={disabled}
                        onChange={(event) =>
                            update({ language: event.target.value })
                        }
                        placeholder="en"
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="gbp-cta-type">Button</Label>
                    <select
                        id="gbp-cta-type"
                        value={options.cta_type ?? ''}
                        disabled={disabled}
                        onChange={(event) =>
                            update({
                                cta_type: event.target.value || undefined,
                            })
                        }
                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {CTA_TYPES.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="gbp-cta-url">CTA URL</Label>
                    <Input
                        id="gbp-cta-url"
                        type="url"
                        value={options.cta_url ?? ''}
                        disabled={disabled}
                        onChange={(event) =>
                            update({ cta_url: event.target.value })
                        }
                        placeholder="https://…"
                    />
                </div>
                {hasSchedule && (
                    <>
                        <div className="space-y-1.5">
                            <Label htmlFor="gbp-start-at">Starts</Label>
                            <Input
                                id="gbp-start-at"
                                type="datetime-local"
                                value={options.start_at ?? ''}
                                disabled={disabled}
                                onChange={(event) =>
                                    update({ start_at: event.target.value })
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="gbp-end-at">Ends</Label>
                            <Input
                                id="gbp-end-at"
                                type="datetime-local"
                                value={options.end_at ?? ''}
                                disabled={disabled}
                                onChange={(event) =>
                                    update({ end_at: event.target.value })
                                }
                            />
                        </div>
                    </>
                )}
                {options.local_post_type === 'offer' && (
                    <>
                        <div className="space-y-1.5">
                            <Label htmlFor="gbp-coupon-code">Coupon code</Label>
                            <Input
                                id="gbp-coupon-code"
                                value={options.coupon_code ?? ''}
                                disabled={disabled}
                                onChange={(event) =>
                                    update({ coupon_code: event.target.value })
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="gbp-redemption-url">
                                Redemption URL
                            </Label>
                            <Input
                                id="gbp-redemption-url"
                                type="url"
                                value={options.redemption_url ?? ''}
                                disabled={disabled}
                                onChange={(event) =>
                                    update({
                                        redemption_url: event.target.value,
                                    })
                                }
                                placeholder="https://…"
                            />
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="gbp-terms">Terms</Label>
                            <Textarea
                                id="gbp-terms"
                                value={options.terms ?? ''}
                                disabled={disabled}
                                onChange={(event) =>
                                    update({ terms: event.target.value })
                                }
                                placeholder="Offer terms and conditions"
                            />
                        </div>
                    </>
                )}
            </div>
        </section>
    );
}
