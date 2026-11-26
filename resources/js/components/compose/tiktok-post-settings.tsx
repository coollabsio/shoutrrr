import type { ReactNode } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Info, Loader2, TriangleAlert } from '@/components/ui/icons';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    BRANDED_CONTENT_POLICY_URL,
    BRANDED_CONTENT_PRIVATE_HINT,
    DISCLOSURE_INCOMPLETE_HINT,
    disclosureLabel,
    isSelfOnly,
    MUSIC_USAGE_URL,
} from '@/lib/compose/tiktok';
import { cn } from '@/lib/utils';
import type {
    TikTokCreatorState,
    TikTokOptions,
    TikTokPostMode,
    TikTokPrivacyLevel,
} from '@/types/compose';

type Props = {
    options: TikTokOptions;
    onChange: (options: TikTokOptions) => void;
    creator: TikTokCreatorState | undefined;
    onRetryCreator: () => void;
    /** Photo posts offer only comments; duet and stitch are video features. */
    isPhotoPost: boolean;
};

const HINT_CLASS = 'text-[12px] leading-4 text-muted-foreground';
const ROW_CLASS = 'px-3 py-2.5';
const FIELD_LABEL_CLASS = 'text-[12.5px] font-medium';

/** The two ways a post reaches TikTok, as offered by the composer toolbar. */
export const TIKTOK_POST_MODES: {
    value: TikTokPostMode;
    label: string;
    title: string;
}[] = [
    {
        value: 'direct',
        label: 'Post',
        title: 'Post straight to the TikTok profile',
    },
    {
        value: 'inbox',
        label: 'Inbox draft',
        title: "Send to the creator's TikTok inbox to finish and post in the app",
    },
];

/**
 * The per-post choices TikTok's Content Sharing Guidelines require before a
 * Direct Post: who is posting, who can view it (no default), which interactions
 * to allow (all off by default, greyed out when the creator turned them off),
 * and whether the post is commercial content. They sit in one TikTok-branded
 * card under the editor; Direct Post or inbox draft is picked in the toolbar,
 * next to where Instagram and Facebook pick their format. Inbox drafts skip
 * the choices — the creator finishes those settings in the TikTok app.
 */
export function TikTokPostSettings({
    options,
    onChange,
    creator,
    onRetryCreator,
    isPhotoPost,
}: Props) {
    const set = (patch: Partial<TikTokOptions>) =>
        onChange({ ...options, ...patch });
    const info = creator?.status === 'available' ? creator.creator : null;
    const media = isPhotoPost ? 'photo' : 'video';
    const interactions = [
        {
            id: 'tiktok-allow-comment',
            label: 'Comment',
            checked: options.allow_comment,
            disabledByCreator: info?.comment_disabled ?? false,
            onChange: (checked: boolean) => set({ allow_comment: checked }),
        },
        ...(isPhotoPost
            ? []
            : [
                  {
                      id: 'tiktok-allow-duet',
                      label: 'Duet',
                      checked: options.allow_duet,
                      disabledByCreator: info?.duet_disabled ?? false,
                      onChange: (checked: boolean) =>
                          set({ allow_duet: checked }),
                  },
                  {
                      id: 'tiktok-allow-stitch',
                      label: 'Stitch',
                      checked: options.allow_stitch,
                      disabledByCreator: info?.stitch_disabled ?? false,
                      onChange: (checked: boolean) =>
                          set({ allow_stitch: checked }),
                  },
              ]),
    ];
    const stillTicked = interactions.filter(
        (interaction) => interaction.disabledByCreator && interaction.checked,
    );

    return (
        <section
            aria-label="TikTok post settings"
            className="border-t border-border px-4 py-3 sm:px-[26px]"
        >
            <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                <CreatorStatus creator={creator} onRetry={onRetryCreator} />

                {options.post_mode === 'inbox' ? (
                    <p className={cn(ROW_CLASS, HINT_CLASS)}>
                        {isPhotoPost
                            ? 'Sent to the TikTok inbox as a draft with the caption. Who can view the post, interactions and disclosure are set in the TikTok app.'
                            : "Sent to the TikTok inbox as a draft. TikTok's inbox doesn't take a caption for videos, so the caption, who can view the post, interactions and disclosure are all set in the TikTok app."}
                    </p>
                ) : (
                    <>
                        <div
                            className={cn(
                                ROW_CLASS,
                                'grid items-center gap-x-4 gap-y-2.5 sm:grid-cols-[auto_minmax(0,1fr)]',
                            )}
                        >
                            <PrivacyField
                                options={options}
                                levels={info?.privacy_levels ?? []}
                                disabled={info === null}
                                onChange={(level) =>
                                    set({ privacy_level: level })
                                }
                            />

                            <span
                                id="tiktok-allow-label"
                                className={FIELD_LABEL_CLASS}
                            >
                                Allow users to
                            </span>
                            <div
                                role="group"
                                aria-labelledby="tiktok-allow-label"
                                className="flex flex-wrap items-center gap-x-5 gap-y-1.5"
                            >
                                {interactions.map((interaction) => (
                                    <InteractionOption
                                        key={interaction.id}
                                        id={interaction.id}
                                        label={interaction.label}
                                        checked={interaction.checked}
                                        unknown={info === null}
                                        disabledByCreator={
                                            interaction.disabledByCreator
                                        }
                                        onChange={interaction.onChange}
                                    />
                                ))}
                            </div>
                            {stillTicked.length > 0 && (
                                <p className="text-[12px] leading-4 text-amber-700 sm:col-start-2 dark:text-amber-500">
                                    {stillTicked
                                        .map((interaction) => interaction.label)
                                        .join(' and ')}{' '}
                                    {stillTicked.length > 1 ? 'are' : 'is'}{' '}
                                    turned off for this TikTok account — untick{' '}
                                    {stillTicked.length > 1 ? 'them' : 'it'}.
                                </p>
                            )}
                        </div>

                        <DisclosureField
                            options={options}
                            media={media}
                            onChange={set}
                        />

                        <p
                            className={cn(
                                ROW_CLASS,
                                HINT_CLASS,
                                'bg-muted/40 py-2',
                            )}
                        >
                            After you publish, TikTok can take a few minutes to
                            process the {media} before it shows on the profile.
                        </p>
                    </>
                )}
            </div>
        </section>
    );
}

/** The card's header: the TikTok mark and who the post goes to. */
function CreatorStatus({
    creator,
    onRetry,
}: {
    creator: TikTokCreatorState | undefined;
    onRetry: () => void;
}) {
    let body: ReactNode;

    if (creator === undefined || creator.status === 'loading') {
        body = (
            <p className="flex items-center gap-1.5 text-[12.5px] text-muted-foreground">
                <Loader2 className="size-3.5 animate-spin" aria-hidden />
                Checking the TikTok account…
            </p>
        );
    } else if (creator.status === 'unavailable') {
        body = (
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2 text-[12.5px] text-amber-700 dark:text-amber-500">
                <TriangleAlert className="size-3.5 shrink-0" aria-hidden />
                <span>{creator.message}</span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onRetry}
                    className="ml-auto"
                >
                    Try again
                </Button>
            </div>
        );
    } else {
        body = (
            <p className="min-w-0 truncate text-[12.5px]">
                <span className="text-muted-foreground">Posting as </span>
                <span className="font-semibold">
                    {creator.creator.nickname}
                </span>
                {creator.creator.username !== '' && (
                    <span className="text-muted-foreground">
                        {' '}
                        @{creator.creator.username}
                    </span>
                )}
            </p>
        );
    }

    return (
        <div className="flex min-h-10 items-center gap-2.5 bg-muted/40 px-3 py-2">
            <span className="grid size-[18px] shrink-0 place-items-center rounded-[5px] bg-black text-white">
                <PlatformGlyph
                    platform="tiktok"
                    size={11}
                    className="text-white"
                />
            </span>
            {body}
        </div>
    );
}

/**
 * Who can view the post. The options come from the creator's live info and no
 * level is preselected; "Only me" is unavailable for branded content.
 */
function PrivacyField({
    options,
    levels,
    disabled,
    onChange,
}: {
    options: TikTokOptions;
    levels: { value: TikTokPrivacyLevel; label: string }[];
    disabled: boolean;
    onChange: (level: TikTokPrivacyLevel | null) => void;
}) {
    const items = levels.map((level) => ({
        value: level.value,
        label: level.label,
    }));

    return (
        <>
            <Label htmlFor="tiktok-privacy" className={FIELD_LABEL_CLASS}>
                Who can view this post
            </Label>
            <div className="space-y-1">
                <Select
                    items={items}
                    value={options.privacy_level}
                    onValueChange={(value) =>
                        onChange((value as TikTokPrivacyLevel | null) ?? null)
                    }
                    disabled={disabled}
                >
                    <SelectTrigger
                        id="tiktok-privacy"
                        size="sm"
                        className="w-full sm:w-56"
                    >
                        <SelectValue placeholder="Choose who can view" />
                    </SelectTrigger>
                    <SelectContent>
                        {levels.map((level) => {
                            const blocked =
                                options.branded_content &&
                                isSelfOnly(level.value);

                            return (
                                <SelectItem
                                    key={level.value}
                                    value={level.value}
                                    disabled={blocked}
                                    title={
                                        blocked
                                            ? BRANDED_CONTENT_PRIVATE_HINT
                                            : undefined
                                    }
                                >
                                    {level.label}
                                </SelectItem>
                            );
                        })}
                    </SelectContent>
                </Select>
                {options.branded_content && (
                    <p className={HINT_CLASS}>{BRANDED_CONTENT_PRIVATE_HINT}</p>
                )}
            </div>
        </>
    );
}

/**
 * One interaction toggle. Off by default; greyed out until the creator's
 * settings are known, and when the creator has turned the interaction off for
 * their account. A box that is still ticked from before stays clickable so it
 * can be unticked (the row says why under the boxes).
 */
function InteractionOption({
    id,
    label,
    checked,
    unknown,
    disabledByCreator,
    onChange,
}: {
    id: string;
    label: string;
    checked: boolean;
    unknown: boolean;
    disabledByCreator: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-center gap-2">
            <Checkbox
                id={id}
                checked={checked}
                disabled={(unknown || disabledByCreator) && !checked}
                onCheckedChange={(value) => onChange(value === true)}
            />
            <Label htmlFor={id} className="text-[12.5px] font-normal">
                {label}
            </Label>
        </div>
    );
}

function DisclosureField({
    options,
    media,
    onChange,
}: {
    options: TikTokOptions;
    media: 'photo' | 'video';
    onChange: (patch: Partial<TikTokOptions>) => void;
}) {
    const label = disclosureLabel(options);
    const privateSelected = isSelfOnly(options.privacy_level);

    return (
        <div className={cn(ROW_CLASS, 'space-y-2.5')}>
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-0.5">
                    <Label
                        htmlFor="tiktok-disclose"
                        className={FIELD_LABEL_CLASS}
                    >
                        Disclose post content
                    </Label>
                    <p className={HINT_CLASS}>
                        Turn on to disclose that this {media} promotes goods or
                        services in exchange for something of value.
                    </p>
                </div>
                <Switch
                    id="tiktok-disclose"
                    checked={options.disclose_commercial}
                    onCheckedChange={(checked) =>
                        onChange(
                            checked
                                ? { disclose_commercial: true }
                                : {
                                      disclose_commercial: false,
                                      brand_organic: false,
                                      branded_content: false,
                                  },
                        )
                    }
                />
            </div>

            {options.disclose_commercial && (
                <div className="grid gap-2 sm:grid-cols-2">
                    <DisclosureOption
                        id="tiktok-brand-organic"
                        label="Your brand"
                        description="You are promoting yourself or your own business."
                        checked={options.brand_organic}
                        onChange={(checked) =>
                            onChange({ brand_organic: checked })
                        }
                    />
                    <DisclosureOption
                        id="tiktok-branded-content"
                        label="Branded content"
                        description="You are promoting another brand or a third party."
                        checked={options.branded_content}
                        disabled={privateSelected && !options.branded_content}
                        disabledHint={
                            privateSelected
                                ? BRANDED_CONTENT_PRIVATE_HINT
                                : undefined
                        }
                        onChange={(checked) =>
                            onChange({ branded_content: checked })
                        }
                    />
                    {label !== null ? (
                        <p className="flex items-start gap-1.5 text-[12px] leading-4 text-muted-foreground sm:col-span-2">
                            <Info
                                className="mt-px size-3.5 shrink-0"
                                aria-hidden
                            />
                            <span>
                                Your {media} will be labeled as &lsquo;{label}
                                &rsquo;.
                            </span>
                        </p>
                    ) : (
                        <p className="text-[12px] leading-4 text-amber-700 sm:col-span-2 dark:text-amber-500">
                            {DISCLOSURE_INCOMPLETE_HINT}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}

function DisclosureOption({
    id,
    label,
    description,
    checked,
    disabled = false,
    disabledHint,
    onChange,
}: {
    id: string;
    label: string;
    description: string;
    checked: boolean;
    disabled?: boolean;
    disabledHint?: string;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div
            className={cn(
                'flex items-start gap-2.5 rounded-md border border-border px-2.5 py-2 transition-colors',
                checked && 'border-foreground/25 bg-muted/50',
            )}
        >
            <Checkbox
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={(value) => onChange(value === true)}
                className="mt-0.5"
            />
            <div className="space-y-0.5">
                <Label htmlFor={id} className="text-[12.5px] font-medium">
                    {label}
                </Label>
                <p className={HINT_CLASS}>
                    {disabled && disabledHint ? disabledHint : description}
                </p>
            </div>
        </div>
    );
}

/**
 * The declaration TikTok requires next to the publish button for a Direct
 * Post; branded content adds TikTok's Branded Content Policy.
 */
export function TikTokConsentNotice({ branded }: { branded: boolean }) {
    return (
        <p className="text-[12px] leading-4 text-muted-foreground">
            By posting, you agree to TikTok&apos;s{' '}
            {branded && (
                <>
                    <ExternalTerm href={BRANDED_CONTENT_POLICY_URL}>
                        Branded Content Policy
                    </ExternalTerm>{' '}
                    and{' '}
                </>
            )}
            <ExternalTerm href={MUSIC_USAGE_URL}>
                Music Usage Confirmation
            </ExternalTerm>
            .
        </p>
    );
}

function ExternalTerm({
    href,
    children,
}: {
    href: string;
    children: ReactNode;
}) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer noopener"
            className="font-medium text-foreground underline underline-offset-2 hover:no-underline"
        >
            {children}
        </a>
    );
}
