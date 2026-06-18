import { Link } from '@inertiajs/react';
import { Plug } from 'lucide-react';
import { useEffect, useReducer, useRef, useState } from 'react';

import { useAutosave } from '@/hooks/compose/use-autosave';
import { useNextSlot } from '@/hooks/compose/use-next-slot';
import { usePublishStatus } from '@/hooks/compose/use-publish-status';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import {
    composerReducer,
    initialComposerState,
    pickActiveAccount,
    type ComposerState,
} from '@/lib/compose/composer-state';
import { postCapabilities } from '@/lib/posts/capabilities';
import { index as accountsRoute } from '@/routes/accounts';
import {
    BASE_TAB,
    type Account,
    type AccountSet,
    type PlatformLimits,
    type PlatformName,
    type PostView,
} from '@/types/compose';

import CharCounter from './char-counter';
import { ComposerToolbar } from './composer-toolbar';
import { ConflictDialog } from './conflict-dialog';
import DestinationSelector from './destination-selector';
import EditorBody from './editor-body';
import PlatformTabs from './platform-tabs';
import SaveIndicator from './save-indicator';
import { ScheduleTray } from './schedule-tray';
import { SubmitBar } from './submit-bar';
import { TargetStatusChips } from './target-status-chips';

type ComposerProps = {
    post: PostView | null;
    accounts: Account[];
    sets: AccountSet[];
    limits: PlatformLimits[];
    /** ISO time to pre-arm the schedule tray with (e.g. from a calendar slot click). */
    initialScheduleAt?: string | null;
};

function accountIdsFor(
    state: ComposerState,
    accounts: Account[],
    sets: AccountSet[],
): string[] {
    const { destination } = state;
    if (destination.kind === 'account') {
        return accounts.filter((a) => a.id === destination.id).map((a) => a.id);
    }
    if (destination.kind === 'set') {
        const set = sets.find((s) => s.id === destination.id);

        return set ? set.connected_account_ids : [];
    }

    return accounts.map((a) => a.id);
}

function measure(text: string, platform: PlatformName): number {
    // oxlint-disable-next-line no-misused-spread -- intentional code-point count
    return platform === 'x' ? text.length : [...text].length;
}

export default function Composer({
    post,
    accounts,
    sets,
    limits,
    initialScheduleAt = null,
}: ComposerProps) {
    const schedulingTz = useSchedulingTimezone();
    // True while any attachment is still uploading — blocks publish/schedule.
    const [mediaUploading, setMediaUploading] = useState(false);
    const [state, dispatch] = useReducer(composerReducer, post, (p) =>
        p
            ? composerReducer(initialComposerState(), {
                  type: 'hydrate',
                  post: p,
              })
            : initialComposerState(initialScheduleAt),
    );

    // Inertia reuses this component across same-page visits (no remount), so
    // the reducer's mount-time hydrate is the only seed. When a navigation or
    // reload delivers a newer/different server `post` — e.g. after a schedule,
    // queue, or publish that mutates `updated_at` outside the autosave path —
    // re-sync so the optimistic-concurrency baseline tracks the server.
    // Autosave uses standalone XHR that never changes this prop, so this never
    // fires for in-flight draft edits.
    const syncedSig = useRef(post ? `${post.id}@${post.updated_at}` : null);
    useEffect(() => {
        if (!post) {
            return;
        }
        const sig = `${post.id}@${post.updated_at}`;
        if (sig === syncedSig.current) {
            return;
        }
        syncedSig.current = sig;
        dispatch({ type: 'syncServerPost', post });
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [post?.id, post?.updated_at]);

    const queueState = useNextSlot(
        state.scheduleTray.mode === 'queue',
        schedulingTz,
    );

    const destinationAccountIds = accountIdsFor(state, accounts, sets);
    const tabAccounts = accounts.filter((a) =>
        destinationAccountIds.includes(a.id),
    );
    const selectedVideoLimits = limits.filter((l) =>
        tabAccounts.some((a) => a.platform === l.platform),
    );
    const { flush, ensurePost } = useAutosave({
        state,
        accountIds: destinationAccountIds,
        dispatch,
    });
    const publishStatus = usePublishStatus({ pagePost: post });

    // A post that isn't a draft is read-only: show its content/media + live
    // status, but no editing, media changes, or re-publishing.
    const readOnly = post !== null && !postCapabilities(post).canEdit;

    const activeAccount = pickActiveAccount(tabAccounts, state.activeTab);
    const activeText =
        activeAccount && state.overrideByAccount[activeAccount.id] !== undefined
            ? (state.overrideByAccount[activeAccount.id] as string)
            : state.baseText;

    function limitFor(platform: PlatformName): number {
        return limits.find((l) => l.platform === platform)?.maxLength ?? 0;
    }

    function severityFor(accountId: string): 'ok' | 'warn' | 'over' {
        const account = tabAccounts.find((a) => a.id === accountId);
        if (!account) {
            return 'ok';
        }
        const text =
            state.overrideByAccount[accountId] !== undefined
                ? (state.overrideByAccount[accountId] as string)
                : state.baseText;
        const limit = limitFor(account.platform);
        const count = measure(text, account.platform);
        if (limit > 0 && count > limit) {
            return 'over';
        }

        return limit > 0 && count >= limit * 0.9 ? 'warn' : 'ok';
    }

    function chipFor(accountId: string): string {
        const account = tabAccounts.find((a) => a.id === accountId);
        if (!account) {
            return '';
        }
        const target = post?.targets.find(
            (t) => t.connected_account_id === accountId,
        );
        if (account.platform === 'linkedin') {
            return (target?.issues.length ?? 0) === 0 ? '✓' : '!';
        }

        return String(target?.sections.length ?? 1);
    }

    function handleText(text: string) {
        if (
            activeAccount &&
            state.overrideByAccount[activeAccount.id] !== undefined
        ) {
            dispatch({
                type: 'setOverrideText',
                accountId: activeAccount.id,
                text,
            });

            return;
        }
        dispatch({ type: 'updateBaseText', text });
    }

    const activeTarget = activeAccount
        ? post?.targets.find((t) => t.connected_account_id === activeAccount.id)
        : undefined;
    const activeSectionTotal = activeTarget?.sections.length ?? 1;
    const overrideActive =
        activeAccount !== null &&
        state.overrideByAccount[activeAccount.id] !== undefined;

    return (
        <div className="overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm transition-[box-shadow,border-color] duration-300 focus-within:border-primary/25 focus-within:shadow-[0_0_16px_-6px_color-mix(in_oklch,var(--primary)_28%,transparent)]">
            {/* Tab-strip row */}
            <div className="flex items-center border-b border-border px-2 pt-2">
                <PlatformTabs
                    accounts={tabAccounts}
                    activeTab={activeAccount?.id ?? state.activeTab}
                    onChange={(tab) => dispatch({ type: 'setActiveTab', tab })}
                    chipFor={chipFor}
                    stateFor={severityFor}
                    hasOverride={(accountId) =>
                        state.overrideByAccount[accountId] !== undefined
                    }
                />
                <div className="ml-auto flex items-center gap-2 pr-1">
                    <DestinationSelector
                        accounts={accounts}
                        sets={sets}
                        destination={state.destination}
                        disabled={readOnly}
                        onChange={(destination) => {
                            dispatch({ type: 'setDestination', destination });
                            void flush();
                        }}
                    />
                    {!readOnly && (
                        <SaveIndicator
                            state={state.saveState}
                            lastSavedAt={
                                state.baselineUpdatedAt
                                    ? Date.parse(state.baselineUpdatedAt)
                                    : null
                            }
                        />
                    )}
                </div>
            </div>

            {/* Override banner (inside EditorBody) + editor */}
            <EditorBody
                value={activeText}
                onChange={handleText}
                onBlur={flush}
                editable={!readOnly}
                overrideBanner={overrideActive}
                activePlatformLabel={activeAccount?.platform ?? null}
                onResetOverride={() =>
                    activeAccount &&
                    dispatch({
                        type: 'discardOverride',
                        accountId: activeAccount.id,
                    })
                }
                markerState={
                    activeAccount
                        ? {
                              platform: activeAccount.platform,
                              autoSplit:
                                  state.autoSplitByAccount[activeAccount.id] ??
                                  true,
                              limit: limitFor(activeAccount.platform),
                              threadMax:
                                  limits.find(
                                      (l) =>
                                          l.platform === activeAccount.platform,
                                  )?.threadMax ?? null,
                          }
                        : undefined
                }
            />

            {/* Counter row — or the connect prompt when there are no accounts. */}
            {activeAccount ? (
                <CharCounter
                    count={measure(activeText, activeAccount.platform)}
                    limit={limitFor(activeAccount.platform)}
                    sectionTotal={activeSectionTotal}
                    state={severityFor(activeAccount.id)}
                />
            ) : (
                <div className="px-4 pb-3.5 sm:px-[26px]">
                    <Link
                        href={accountsRoute().url}
                        className="inline-flex items-center gap-1.5 rounded-md border border-dashed border-border px-2.5 py-1 text-[12px] tracking-[-0.005em] text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-foreground"
                    >
                        <Plug className="size-3.5" aria-hidden />
                        Connect an account to publish
                    </Link>
                </div>
            )}

            {/* Toolbar — editing controls when editable; just the attached
                media when read-only (skipped entirely if there's none). */}
            {(!readOnly || state.media.length > 0) && (
                <ComposerToolbar
                    readOnly={readOnly}
                    activePlatform={activeAccount?.platform}
                    autoSplit={
                        activeAccount
                            ? (state.autoSplitByAccount[activeAccount.id] ??
                              true)
                            : false
                    }
                    overrideActive={overrideActive}
                    showSplitControls={activeAccount !== null}
                    media={state.media}
                    onAddMedia={(m) => dispatch({ type: 'addMedia', media: m })}
                    onRemove={(id) =>
                        dispatch({ type: 'removeMedia', mediaId: id })
                    }
                    onReorder={(ids) => dispatch({ type: 'reorderMedia', ids })}
                    onToggleAutoSplit={() =>
                        activeAccount &&
                        dispatch({
                            type: 'toggleAutoSplit',
                            accountId: activeAccount.id,
                        })
                    }
                    onToggleOverride={() => {
                        if (!activeAccount) {
                            return;
                        }
                        if (
                            state.overrideByAccount[activeAccount.id] !==
                            undefined
                        ) {
                            dispatch({
                                type: 'discardOverride',
                                accountId: activeAccount.id,
                            });
                        } else {
                            dispatch({
                                type: 'setOverrideText',
                                accountId: activeAccount.id,
                                text: state.baseText,
                            });
                        }
                    }}
                    isExcluded={(mediaId) =>
                        activeAccount
                            ? state.mediaSubsetExcludes.has(
                                  `${mediaId}:${activeAccount.id}`,
                              )
                            : false
                    }
                    onToggleExclude={(mediaId) =>
                        activeAccount &&
                        dispatch({
                            type: 'toggleMediaExclude',
                            mediaId,
                            accountId: activeAccount.id,
                        })
                    }
                    onEnsurePost={ensurePost}
                    videoLimits={selectedVideoLimits}
                    onUploadingChange={setMediaUploading}
                />
            )}

            {/* Schedule + submit row — hidden once the post is read-only. */}
            {!readOnly && (
                <div className="flex flex-col items-stretch gap-3 border-t border-border bg-muted/55 px-3 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-x-3 sm:px-[14px]">
                    <ScheduleTray
                        tray={state.scheduleTray}
                        onChange={(tray) =>
                            dispatch({ type: 'setScheduleTray', tray })
                        }
                        tz={schedulingTz}
                        queueState={queueState}
                    />
                    <SubmitBar
                        tray={state.scheduleTray}
                        postId={state.postId}
                        disabled={accounts.length === 0}
                        queueDisabled={queueState.status !== 'found'}
                        uploading={mediaUploading}
                        onSaveDraft={flush}
                        onEnsurePost={ensurePost}
                        onOptimisticSubmit={publishStatus.applyOptimistic}
                        onServerPost={publishStatus.applyServerPost}
                    />
                </div>
            )}

            {/* Live publish status — only once a publish/queue/schedule has run */}
            {publishStatus.snapshot &&
                publishStatus.snapshot.status !== 'draft' &&
                publishStatus.snapshot.targets.length > 0 && (
                    <div className="border-t border-border px-3 py-3 sm:px-[14px]">
                        <TargetStatusChips
                            targets={publishStatus.snapshot.targets}
                            retryingIds={publishStatus.retryingIds}
                            onRetry={(targetId) =>
                                void publishStatus.retry(targetId)
                            }
                        />
                    </div>
                )}

            {state.conflict !== null && (
                <ConflictDialog
                    open
                    myBaseText={state.baseText}
                    serverPost={state.conflict}
                    onKeepMine={() =>
                        dispatch({ type: 'resolveConflictKeepMine' })
                    }
                    onUseServer={() =>
                        dispatch({ type: 'resolveConflictUseServer' })
                    }
                />
            )}
        </div>
    );
}

export { BASE_TAB };
