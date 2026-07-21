import { router } from '@inertiajs/react';
import { AlertTriangle, ChevronDown } from 'lucide-react';
import type React from 'react';
import { useState } from 'react';

import { PlatformGlyph } from '@/components/common/platform-glyph';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { index as accountsRoute } from '@/routes/accounts';
import { BASE_TAB, type Account } from '@/types/compose';

const PLATFORM_BRAND: Record<string, { tile: string; glyph: string }> = {
    x: { tile: 'bg-white', glyph: 'text-black!' },
    linkedin: { tile: 'bg-blue-600', glyph: 'text-white!' },
    bluesky: { tile: 'bg-sky-500', glyph: 'text-white!' },
    facebook: { tile: 'bg-[#1877F2]', glyph: 'text-white!' },
    instagram: { tile: 'bg-[#E4405F]', glyph: 'text-white!' },
    threads: { tile: 'bg-black', glyph: 'text-white!' },
    discord: { tile: 'bg-[#5865F2]', glyph: 'text-white!' },
};

const PLATFORM_FALLBACK = { tile: 'bg-muted', glyph: 'text-muted-foreground' };
const VISIBLE_ACCOUNT_TABS = 4;

type PlatformTabsProps = {
    /** One tab per destination account. Empty → a single generic "Post" tab. */
    accounts: Account[];
    /** Active tab: an account id, or `BASE_TAB`. */
    activeTab: string;
    onChange: (tab: string) => void;
    /** Per-account count chip text, e.g. "4" (section count) or "✓" / "!". */
    chipFor: (accountId: string) => string;
    /** Per-account severity driving the `after:` underline tint. */
    stateFor: (accountId: string) => 'ok' | 'warn' | 'over';
    /** Returns true when the given account has a content override active. */
    hasOverride: (accountId: string) => boolean;
    /** Account ids with an active non-blocking format notice (e.g. Stories caption drop). */
    noticeAccountIds: string[];
};

export function visiblePlatformTabAccounts(
    accounts: Account[],
    activeTab: string,
    visibleLimit = VISIBLE_ACCOUNT_TABS,
): { visibleAccounts: Account[]; overflowAccounts: Account[] } {
    if (accounts.length <= visibleLimit) {
        return { visibleAccounts: accounts, overflowAccounts: [] };
    }

    const visibleAccounts = accounts.slice(0, visibleLimit);

    if (!visibleAccounts.some((account) => account.id === activeTab)) {
        const activeAccount = accounts.find(
            (account) => account.id === activeTab,
        );

        if (activeAccount) {
            visibleAccounts[visibleAccounts.length - 1] = activeAccount;
        }
    }

    const visibleIds = new Set(visibleAccounts.map((account) => account.id));

    return {
        visibleAccounts,
        overflowAccounts: accounts.filter(
            (account) => !visibleIds.has(account.id),
        ),
    };
}

const TAB_CLASS = cn(
    'group/tab relative flex min-w-0 items-center gap-2 rounded-t-md px-3 pt-2 pb-2.5 text-[12.5px] font-medium tracking-[-0.005em] transition-colors',
    'text-muted-foreground hover:bg-muted hover:text-foreground',
    'data-[active=true]:text-foreground',
    'after:absolute after:inset-x-2 after:-bottom-px after:h-0.5 after:rounded-t-sm after:bg-foreground after:opacity-0 data-[active=true]:after:opacity-100',
    'data-[state=over]:after:bg-destructive data-[state=warn]:after:bg-amber-500',
);

export default function PlatformTabs({
    accounts,
    activeTab,
    onChange,
    chipFor,
    stateFor,
    hasOverride,
    noticeAccountIds,
}: PlatformTabsProps) {
    // No accounts → one generic, platform-less tab that edits the base text.
    if (accounts.length === 0) {
        return (
            <div
                className="flex min-w-0 flex-1 items-end gap-0.5 overflow-x-auto overflow-y-hidden"
                role="tablist"
                aria-label="Post"
            >
                <button
                    type="button"
                    role="tab"
                    aria-selected={activeTab === BASE_TAB}
                    data-active={activeTab === BASE_TAB}
                    data-state="ok"
                    onClick={() => onChange(BASE_TAB)}
                    className={TAB_CLASS}
                >
                    <span className="grid size-[18px] place-items-center rounded-[5px] bg-foreground text-background">
                        <span className="size-1 rounded-full bg-background" />
                    </span>
                    <span>Post</span>
                </button>
            </div>
        );
    }

    const mobileTabs = visiblePlatformTabAccounts(accounts, activeTab, 2);
    const desktopTabs = visiblePlatformTabAccounts(accounts, activeTab);

    // Each row owns its overflow-popover state. The two rows are mutually
    // exclusive via CSS (`md:hidden` / `hidden md:flex`), but both stay mounted,
    // so a shared open-state would open the hidden row's popover too — and its
    // dismissable layer, anchored to a display:none trigger, reads the trigger
    // click as an outside-click and slams the shared state shut again.
    return (
        <>
            <PlatformTabRow
                className="flex md:hidden"
                visibleAccounts={mobileTabs.visibleAccounts}
                compact
                overflowAccounts={mobileTabs.overflowAccounts}
                activeTab={activeTab}
                onChange={onChange}
                chipFor={chipFor}
                stateFor={stateFor}
                hasOverride={hasOverride}
                noticeAccountIds={noticeAccountIds}
            />
            <PlatformTabRow
                className="hidden md:flex"
                visibleAccounts={desktopTabs.visibleAccounts}
                overflowAccounts={desktopTabs.overflowAccounts}
                activeTab={activeTab}
                onChange={onChange}
                chipFor={chipFor}
                stateFor={stateFor}
                hasOverride={hasOverride}
                noticeAccountIds={noticeAccountIds}
            />
        </>
    );
}

function PlatformTabRow({
    className,
    visibleAccounts,
    overflowAccounts,
    activeTab,
    onChange,
    chipFor,
    stateFor,
    hasOverride,
    noticeAccountIds,
    compact = false,
}: {
    className: string;
    visibleAccounts: Account[];
    overflowAccounts: Account[];
    activeTab: string;
    onChange: (tab: string) => void;
    chipFor: (accountId: string) => string;
    stateFor: (accountId: string) => 'ok' | 'warn' | 'over';
    hasOverride: (accountId: string) => boolean;
    noticeAccountIds: string[];
    compact?: boolean;
}) {
    const [overflowOpen, setOverflowOpen] = useState(false);

    return (
        <div
            className={cn(
                'min-w-0 flex-1 items-end gap-0.5 overflow-hidden',
                className,
            )}
            role="tablist"
            aria-label="Accounts"
        >
            {visibleAccounts.map((account) => (
                <PlatformTabButton
                    key={account.id}
                    account={account}
                    activeTab={activeTab}
                    onChange={onChange}
                    chipFor={chipFor}
                    stateFor={stateFor}
                    hasOverride={hasOverride}
                    hasNotice={noticeAccountIds.includes(account.id)}
                    compact={compact}
                />
            ))}
            {overflowAccounts.length > 0 && (
                <Popover open={overflowOpen} onOpenChange={setOverflowOpen}>
                    <PopoverTrigger
                        render={
                            <button
                                type="button"
                                className={cn(
                                    TAB_CLASS,
                                    'shrink-0 gap-1.5',
                                    compact && 'px-2',
                                    'data-[popup-open]:bg-muted data-[popup-open]:text-foreground',
                                )}
                                aria-label={`${overflowAccounts.length} more accounts`}
                            />
                        }
                    >
                        <span>+{overflowAccounts.length} more</span>
                        <ChevronDown className="size-3 opacity-70 transition-transform duration-150 group-data-[popup-open]/tab:rotate-180" />
                    </PopoverTrigger>
                    <PopoverContent
                        align="start"
                        sideOffset={6}
                        className="w-[236px] gap-0.5 rounded-2xl p-1.5"
                    >
                        <div className="px-2 pt-1 pb-1.5 text-[11px] font-medium tracking-tight text-muted-foreground">
                            {overflowAccounts.length} more{' '}
                            {overflowAccounts.length === 1
                                ? 'account'
                                : 'accounts'}
                        </div>
                        {overflowAccounts.map((account) => (
                            <PlatformTabButton
                                key={account.id}
                                account={account}
                                activeTab={activeTab}
                                onChange={(tab) => {
                                    onChange(tab);
                                    setOverflowOpen(false);
                                }}
                                chipFor={chipFor}
                                stateFor={stateFor}
                                hasOverride={hasOverride}
                                hasNotice={noticeAccountIds.includes(
                                    account.id,
                                )}
                                inMenu
                            />
                        ))}
                    </PopoverContent>
                </Popover>
            )}
        </div>
    );
}

function PlatformTabButton({
    account,
    activeTab,
    onChange,
    chipFor,
    stateFor,
    hasOverride,
    hasNotice = false,
    inMenu = false,
    compact = false,
}: {
    account: Account;
    activeTab: string;
    onChange: (tab: string) => void;
    chipFor: (accountId: string) => string;
    stateFor: (accountId: string) => 'ok' | 'warn' | 'over';
    hasOverride: (accountId: string) => boolean;
    hasNotice?: boolean;
    inMenu?: boolean;
    compact?: boolean;
}) {
    const isActive = account.id === activeTab;
    const severity = stateFor(account.id);
    const overridden = hasOverride(account.id);
    const brand = PLATFORM_BRAND[account.platform] ?? PLATFORM_FALLBACK;
    const needsAttention = account.status === 'needs_attention';
    const sectionChip = chipFor(account.id);
    // The section-count chip only carries meaning past one (a threaded post). In
    // the overflow menu a lone "1" on every row is just noise, so drop it there.
    const showChip = !inMenu || sectionChip !== '1';

    return (
        <button
            type="button"
            role="tab"
            aria-selected={isActive}
            data-active={isActive}
            data-state={severity}
            onClick={() => onChange(account.id)}
            title={account.display_name ?? account.handle}
            className={cn(
                TAB_CLASS,
                inMenu &&
                    'h-9 w-full rounded-xl px-2 pt-1.5 pb-1.5 after:hidden',
                compact && !inMenu && 'min-w-0 flex-1 basis-0 gap-1.5 px-2',
            )}
        >
            <span
                className={cn(
                    'grid size-[18px] shrink-0 place-items-center rounded-[5px]',
                    brand.tile,
                    brand.glyph,
                )}
            >
                <PlatformGlyph
                    platform={account.platform}
                    size={11}
                    className={brand.glyph}
                />
            </span>
            <span
                className={cn('min-w-0 truncate', inMenu && 'flex-1 text-left')}
            >
                {account.handle}
            </span>
            {overridden && (
                <span
                    className="size-1.5 shrink-0 rounded-full bg-primary"
                    aria-label="override active"
                    title="Override active on this account"
                />
            )}
            {hasNotice && (
                <span
                    className="size-1.5 shrink-0 rounded-full bg-amber-500"
                    aria-label="format notice"
                    title="This account has a format warning"
                />
            )}
            {needsAttention && <NeedsAttentionIcon account={account} />}
            {showChip && (
                <span className="shrink-0 font-mono text-[11px] text-muted-foreground tabular-nums">
                    {sectionChip}
                </span>
            )}
        </button>
    );
}

function NeedsAttentionIcon({ account }: { account: Account }) {
    function openAccounts(event: React.MouseEvent | React.KeyboardEvent) {
        event.preventDefault();
        event.stopPropagation();
        router.visit(accountsRoute().url);
    }

    return (
        <Tooltip>
            <TooltipTrigger
                render={
                    <span
                        role="link"
                        tabIndex={0}
                        aria-label={`${account.handle} needs attention`}
                        onClick={openAccounts}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                                openAccounts(event);
                            }
                        }}
                        className="inline-grid size-4 shrink-0 cursor-pointer place-items-center rounded-sm text-destructive outline-hidden hover:bg-destructive/10 focus-visible:ring-2 focus-visible:ring-destructive/40"
                    />
                }
            >
                <AlertTriangle className="size-3.5" aria-hidden />
            </TooltipTrigger>
            <TooltipContent side="top">
                Reconnect {account.handle} before posting.
            </TooltipContent>
        </Tooltip>
    );
}
