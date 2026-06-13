import { Link } from '@inertiajs/react';
import { useReducer } from 'react';

import { index as accountsRoute } from '@/routes/accounts';

import CharCounter from './CharCounter';
import {
    composerReducer,
    initialComposerState,
    pickActiveAccount,
    type ComposerState,
} from './composer-state';
import { ComposerToolbar } from './ComposerToolbar';
import { ConflictDialog } from './ConflictDialog';
import DestinationSelector from './DestinationSelector';
import EditorBody from './EditorBody';
import PlatformTabs from './PlatformTabs';
import SaveIndicator from './SaveIndicator';
import { ScheduleTray } from './ScheduleTray';
import { SubmitBar } from './SubmitBar';
import { TargetStatusChips } from './TargetStatusChips';
import {
    BASE_TAB,
    type Account,
    type AccountSet,
    type PlatformLimits,
    type PlatformName,
    type PostView,
} from './types';
import { useAutosave } from './use-autosave';
import { usePublishStatus } from './use-publish-status';

type ComposerProps = {
    post: PostView | null;
    accounts: Account[];
    sets: AccountSet[];
    limits: PlatformLimits[];
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
}: ComposerProps) {
    const [state, dispatch] = useReducer(composerReducer, post, (p) =>
        p
            ? composerReducer(initialComposerState(), {
                  type: 'hydrate',
                  post: p,
              })
            : initialComposerState(),
    );

    const destinationAccountIds = accountIdsFor(state, accounts, sets);
    const tabAccounts = accounts.filter((a) =>
        destinationAccountIds.includes(a.id),
    );
    const { flush, ensurePost } = useAutosave({
        state,
        accountIds: destinationAccountIds,
        dispatch,
    });
    const publishStatus = usePublishStatus({ pagePost: post });

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
        <div className="overflow-hidden rounded-xl border bg-card text-card-foreground">
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
                        onChange={(destination) => {
                            dispatch({ type: 'setDestination', destination });
                            flush();
                        }}
                    />
                    <SaveIndicator
                        state={state.saveState}
                        lastSavedAt={
                            state.baselineUpdatedAt
                                ? Date.parse(state.baselineUpdatedAt)
                                : null
                        }
                    />
                </div>
            </div>

            {/* Override banner (inside EditorBody) + editor */}
            <EditorBody
                value={activeText}
                onChange={handleText}
                onBlur={flush}
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
                <div className="flex min-h-6 items-center px-4 pb-3.5 text-[12px] text-muted-foreground sm:px-[26px]">
                    <Link
                        href={accountsRoute().url}
                        className="tracking-[-0.005em] underline-offset-2 hover:text-foreground hover:underline"
                    >
                        Connect an account to publish
                    </Link>
                </div>
            )}

            {/* Toolbar */}
            <ComposerToolbar
                activePlatform={activeAccount?.platform}
                autoSplit={
                    activeAccount
                        ? (state.autoSplitByAccount[activeAccount.id] ?? true)
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
                        state.overrideByAccount[activeAccount.id] !== undefined
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
            />

            {/* Schedule + submit row */}
            <div className="flex items-center justify-between gap-x-3 border-t border-border bg-muted/55 px-3 py-3 sm:px-[14px]">
                <ScheduleTray
                    tray={state.scheduleTray}
                    onChange={(tray) =>
                        dispatch({ type: 'setScheduleTray', tray })
                    }
                />
                <SubmitBar
                    tray={state.scheduleTray}
                    postId={state.postId}
                    disabled={accounts.length === 0}
                    onSaveDraft={flush}
                    onEnsurePost={ensurePost}
                    onSubmitted={(submitted) => {
                        dispatch({ type: 'saveSucceeded', post: submitted });
                        publishStatus.applyServerPost(submitted);
                    }}
                />
            </div>

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
