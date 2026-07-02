import { Link, useHttp } from '@inertiajs/react';
import { Eye, Pin, Plug } from 'lucide-react';
import { useEffect, useReducer, useRef, useState } from 'react';
import { toast } from 'sonner';

import WorkspaceMentionController from '@/actions/App/Http/Controllers/WorkspaceMentionController';
import { useAutosave } from '@/hooks/compose/use-autosave';
import { useImageEditor } from '@/hooks/compose/use-image-editor';
import { useMediaUploads } from '@/hooks/compose/use-media-uploads';
import { useNextSlot } from '@/hooks/compose/use-next-slot';
import { usePublishStatus } from '@/hooks/compose/use-publish-status';
import { useVideoEditor } from '@/hooks/compose/use-video-editor';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import {
    composerReducer,
    initialComposerState,
    pickActiveAccount,
    shouldShowConnectAccountPrompt,
    type ComposerState,
} from '@/lib/compose/composer-state';
import {
    replaceMentionLabel,
    replaceMentionTokens,
    savedMentionToPlaceholder,
    syncMentionsFromText,
} from '@/lib/compose/mentions';
import { buildPlatformPreview } from '@/lib/compose/platform-preview';
import { readVideoMetadata } from '@/lib/compose/video';
import {
    defaultSettings,
    normalizeSettings,
    type EditSettings,
} from '@/lib/image-editor/settings';
import { postCapabilities } from '@/lib/posts/capabilities';
import { cn } from '@/lib/utils';
import { index as accountsRoute } from '@/routes/accounts';
import {
    BASE_TAB,
    type Account,
    type AccountSet,
    type Destination,
    type MentionPlaceholder,
    type PlatformLimits,
    type PlatformName,
    type PostView,
    type WorkspaceMention,
} from '@/types/compose';

import CharCounter from './char-counter';
import { ComposerToolbar } from './composer-toolbar';
import { ConflictDialog } from './conflict-dialog';
import DestinationSelector from './destination-selector';
import EditorBody from './editor-body';
import { ImageEditor } from './image-editor';
import { PlatformPreviewPanel } from './platform-preview-panel';
import PlatformTabs from './platform-tabs';
import SaveIndicator from './save-indicator';
import { ScheduleTray } from './schedule-tray';
import { SubmitBar } from './submit-bar';
import { TargetStatusChips } from './target-status-chips';
import { VideoEditor } from './video-editor';

/** What the image editor is currently working on. */
type Editing =
    | {
          kind: 'batch';
          items: { file: File; url: string }[];
          index: number;
      }
    | {
          kind: 'reedit';
          url: string;
          settings: EditSettings;
          mediaId: string;
          altText: string | null;
      }
    | { kind: 'raw'; url: string; mediaId: string }
    | {
          kind: 'video';
          url: string;
          durationSeconds: number;
          mediaId: string;
          altText: string | null;
      }
    | { kind: 'video-new'; url: string; durationSeconds: number; file: File };

/** Stable fallback so a closed editor doesn't reallocate settings each render. */
const DEFAULT_EDIT_SETTINGS = defaultSettings();

/** Placeholder identity for the default X preview shown before any account is connected. */
const PREVIEW_FALLBACK_ACCOUNT: Account = {
    id: 'preview-fallback-x',
    platform: 'x',
    handle: '@yourhandle',
    display_name: 'Your name',
    avatar_url: null,
    status: 'active',
    max_text_length: 0,
    x_premium: false,
};

const PREVIEW_PINNED_STORAGE_KEY = 'shoutrrr.composer.previewPinned';

type ComposerProps = {
    post: PostView | null;
    accounts: Account[];
    sets: AccountSet[];
    limits: PlatformLimits[];
    /** ISO time to pre-arm the schedule tray with (e.g. from a calendar slot click). */
    initialScheduleAt?: string | null;
    /** Seed the destination for a brand-new post (e.g. compose-for-channel). */
    initialDestination?: Destination | null;
    /** Focus the editor as soon as it mounts. */
    autoFocusEditor?: boolean;
    initialSavedMentions?: WorkspaceMention[];
};

const EMPTY_SAVED_MENTIONS: WorkspaceMention[] = [];

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
    if (destination.kind === 'accounts') {
        const selected = new Set(destination.ids);

        return accounts.filter((a) => selected.has(a.id)).map((a) => a.id);
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
    initialDestination = null,
    autoFocusEditor = false,
    initialSavedMentions = EMPTY_SAVED_MENTIONS,
}: ComposerProps) {
    const schedulingTz = useSchedulingTimezone();
    const saveMentionHttp = useHttp<
        Record<string, never>,
        { mention: WorkspaceMention }
    >({});
    const [savedMentions, setSavedMentions] = useState(initialSavedMentions);
    useEffect(() => {
        setSavedMentions(initialSavedMentions);
    }, [initialSavedMentions]);
    const [state, dispatch] = useReducer(composerReducer, post, (p) =>
        p
            ? composerReducer(initialComposerState(), {
                  type: 'hydrate',
                  post: p,
              })
            : initialComposerState(initialScheduleAt, initialDestination),
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
    const attentionAccounts = tabAccounts.filter(
        (account) => account.status === 'needs_attention',
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

    // Owns the media-upload pipeline (image/video validation + upload). Lifted
    // here so both the editor (⌘/Ctrl+V paste) and the toolbar (picker/drop)
    // feed the same handleFiles and share one in-flight `pending` list.
    const mediaUploads = useMediaUploads({
        media: state.media,
        videoLimits: selectedVideoLimits,
        onEnsurePost: ensurePost,
        onAddMedia: (m) => dispatch({ type: 'addMedia', media: m }),
    });

    const imageEditor = useImageEditor({
        onEnsurePost: ensurePost,
        onAddMedia: (m) => dispatch({ type: 'addMedia', media: m }),
        onReplaceMedia: (m) => dispatch({ type: 'replaceMedia', media: m }),
    });

    const videoEditor = useVideoEditor({
        onEnsurePost: ensurePost,
        onComplete: (oldMediaId, media) => {
            dispatch({ type: 'addMedia', media });
            if (oldMediaId) {
                dispatch({ type: 'removeMedia', mediaId: oldMediaId });
            }
        },
    });

    // The editor opens automatically when image(s) are added and when an attached
    // image is clicked. A multi-image add becomes a `batch` edited one item at a
    // time; the editor shows the batch as a thumbnail strip.
    const [editing, setEditing] = useState<Editing | null>(null);
    // Platform preview is opt-in: collapsed by default, revealed via the toolbar
    // "Preview" toggle so it doesn't crowd the editor.
    const [showPreview, setShowPreview] = useState(false);
    const [previewPinned, setPreviewPinned] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return (
            window.localStorage.getItem(PREVIEW_PINNED_STORAGE_KEY) === 'true'
        );
    });
    const previewVisible = showPreview || previewPinned;
    useEffect(() => {
        window.localStorage.setItem(
            PREVIEW_PINNED_STORAGE_KEY,
            String(previewPinned),
        );
    }, [previewPinned]);
    // Revoke any outstanding batch object URLs if the composer unmounts mid-batch.
    const editingRef = useRef<Editing | null>(null);
    editingRef.current = editing;
    useEffect(
        () => () => {
            const e = editingRef.current;
            if (e?.kind === 'batch') {
                for (const it of e.items) {
                    URL.revokeObjectURL(it.url);
                }
            } else if (e?.kind === 'video-new') {
                URL.revokeObjectURL(e.url);
            }
        },
        [],
    );

    // Advance to the next batch image, or close the editor (revoking the batch's
    // object URLs) when the batch is done. Re-edits just close.
    function endEditingStep() {
        if (editing?.kind === 'batch') {
            if (editing.index + 1 < editing.items.length) {
                setEditing({ ...editing, index: editing.index + 1 });

                return;
            }
            for (const it of editing.items) {
                URL.revokeObjectURL(it.url);
            }
        }
        setEditing(null);
    }

    // Split a picked/dropped/pasted batch: videos upload directly; images open
    // the editor as a batch (edited one at a time).
    async function handleAddedFiles(files: FileList | File[]): Promise<void> {
        const all = Array.from(files);
        const videos = all.filter((f) => f.type.startsWith('video/'));
        // Anything that isn't a video is treated as an image: some clipboard
        // pastes report an empty/unknown MIME type, and the server validates the
        // real content type on upload.
        const images = all.filter((f) => !f.type.startsWith('video/'));

        // A post is one video OR images, never both — decide before uploading
        // anything, so a mixed drop doesn't half-attach the video.
        const hasVideo = state.media.some((m) => m.kind === 'video');
        const hasImage = state.media.some((m) => m.kind !== 'video');
        if (
            (videos.length > 0 && (images.length > 0 || hasImage)) ||
            (images.length > 0 && hasVideo)
        ) {
            toast.error('A post can contain one video or images, not both.');

            return;
        }

        if (videos.length > 0) {
            const file = videos[0];
            try {
                const meta = await readVideoMetadata(file);
                setEditing({
                    kind: 'video-new',
                    url: URL.createObjectURL(file),
                    durationSeconds: meta.durationSeconds,
                    file,
                });
            } catch {
                toast.error('That video could not be read.');
            }

            return;
        }
        if (images.length === 0) {
            return;
        }
        setEditing({
            kind: 'batch',
            items: images.map((f) => ({
                file: f,
                url: URL.createObjectURL(f),
            })),
            index: 0,
        });
    }

    // Revoke the object URL for a video-new session and close the editor.
    function closeVideoEditing() {
        if (editing?.kind === 'video-new') {
            URL.revokeObjectURL(editing.url);
        }
        setEditing(null);
    }

    // Open an attached video in the video editor.
    function openVideo(mediaId: string) {
        const m = state.media.find((x) => x.id === mediaId);
        if (!m || m.kind !== 'video') {
            return;
        }
        setEditing({
            kind: 'video',
            url: m.url,
            durationSeconds: m.duration_seconds ?? 0,
            mediaId: m.id,
            altText: m.alt_text,
        });
    }

    // Re-open an attached image: a beautified one rehydrates from its persisted
    // source + settings; a plain one is beautified from scratch.
    function openImage(mediaId: string) {
        const m = state.media.find((x) => x.id === mediaId);
        if (!m || m.kind === 'video') {
            return;
        }
        if (m.edit_settings && m.source_url) {
            setEditing({
                kind: 'reedit',
                url: m.source_url,
                settings: normalizeSettings(m.edit_settings),
                mediaId: m.id,
                altText: m.alt_text,
            });
        } else {
            setEditing({ kind: 'raw', url: m.url, mediaId: m.id });
        }
    }

    // Apply: persist the composed image, then advance the batch / close.
    async function applyEditing(
        composed: Blob,
        settings: EditSettings,
        altText: string,
    ): Promise<void> {
        if (!editing) {
            return;
        }
        // On a failed save the editor stays open (the hook already toasted) so the
        // user can retry — and, crucially, we never drop the original attachment.
        if (editing.kind === 'batch') {
            const ok = await imageEditor.applyNew(
                composed,
                editing.items[editing.index].file,
                settings,
                altText,
            );
            if (!ok) {
                return;
            }
        } else if (editing.kind === 'reedit') {
            const ok = await imageEditor.applyEdit(
                editing.mediaId,
                composed,
                settings,
                altText,
            );
            if (!ok) {
                return;
            }
        } else if (editing.kind === 'raw') {
            // A plain image beautified for the first time: keep the raw image as
            // the source, attach the composed result, drop the raw attachment.
            const rawBlob = await fetch(editing.url).then((r) => r.blob());
            const ok = await imageEditor.applyNew(
                composed,
                rawBlob,
                settings,
                altText,
            );
            if (!ok) {
                return;
            }
            dispatch({ type: 'removeMedia', mediaId: editing.mediaId });
        }
        endEditingStep();
    }

    // Continue without editing: a freshly-added image still attaches as-is (raw);
    // re-edits just close with no change.
    function cancelEditing() {
        if (editing?.kind === 'batch') {
            void mediaUploads.handleFiles([editing.items[editing.index].file]);
        }
        endEditingStep();
    }

    // Remove/discard: drop a fresh upload without attaching, or remove an existing
    // attached image from the post.
    function discardEditing() {
        if (editing?.kind === 'reedit' || editing?.kind === 'raw') {
            dispatch({ type: 'removeMedia', mediaId: editing.mediaId });
        }
        endEditingStep();
    }

    // Resolve the editor's current source/settings/queue from `editing`.
    const editorSourceUrl =
        editing?.kind === 'batch'
            ? editing.items[editing.index].url
            : (editing?.url ?? null);
    const editorSettings =
        editing?.kind === 'reedit' ? editing.settings : DEFAULT_EDIT_SETTINGS;
    const editorAltText = editing?.kind === 'reedit' ? editing.altText : null;
    const editorQueue =
        editing?.kind === 'batch'
            ? {
                  thumbnails: editing.items.map((it) => it.url),
                  index: editing.index,
              }
            : undefined;

    // Persist a destination change immediately rather than waiting out the
    // autosave debounce. This MUST run in an effect — AFTER the reducer commits
    // — so `flush` closes over the new destination. Calling flush() synchronously
    // inside the selector's onChange captured the PRE-dispatch state and
    // persisted the OLD destination, so a quick switch-then-publish published to
    // the previous set (e.g. the default "all accounts"). flush's own guards make
    // this a no-op on mount and on server-driven hydrates (saveState is 'saved'),
    // so it only fires for genuine user switches.
    const flushedDestination = useRef(state.destination);
    useEffect(() => {
        if (flushedDestination.current === state.destination) {
            return;
        }
        flushedDestination.current = state.destination;
        void flush();
        // oxlint-disable-next-line react-hooks/exhaustive-deps
    }, [state.destination]);

    // A post that isn't a draft is read-only: show its content/media + live
    // status, but no editing, media changes, or re-publishing.
    const readOnly = post !== null && !postCapabilities(post).canEdit;

    const activeAccount = pickActiveAccount(tabAccounts, state.activeTab);
    const showConnectAccountPrompt = shouldShowConnectAccountPrompt(
        accounts,
        activeAccount,
    );
    const activeSegments =
        activeAccount && state.overrideByAccount[activeAccount.id] !== undefined
            ? (state.overrideByAccount[activeAccount.id] as string[])
            : state.segments;

    function limitForPlatform(platform: PlatformName): number {
        return limits.find((l) => l.platform === platform)?.maxLength ?? 0;
    }

    function limitForAccount(account: Account): number {
        return account.max_text_length || limitForPlatform(account.platform);
    }

    function severityFor(accountId: string): 'ok' | 'warn' | 'over' {
        const account = tabAccounts.find((a) => a.id === accountId);
        if (!account) {
            return 'ok';
        }
        const segments =
            state.overrideByAccount[accountId] !== undefined
                ? (state.overrideByAccount[accountId] as string[])
                : state.segments;
        const resolvedText = replaceMentionTokens(
            segments.join('\n'),
            state.mentions,
            account.platform,
        );
        const limit = limitForAccount(account);
        const count = measure(resolvedText, account.platform);
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
        return String(target?.sections.length ?? 1);
    }

    function syncMentions(
        nextSegments: string[],
        nextOverrides = state.overrideByAccount,
    ) {
        const mentionSource = [
            nextSegments.join('\n'),
            ...Object.values(nextOverrides).map((s) => (s ?? []).join('\n')),
        ].join('\n');
        const mentions = syncMentionsFromText(
            mentionSource,
            state.mentions,
            savedMentions,
        );
        if (JSON.stringify(mentions) !== JSON.stringify(state.mentions)) {
            dispatch({ type: 'setMentions', mentions });
        }
    }

    function renameMention(
        mention: MentionPlaceholder,
        next: MentionPlaceholder,
    ) {
        const replaceSeg = (segments: string[]): string[] =>
            segments.map((s) =>
                replaceMentionLabel(s, mention.label, next.label),
            );
        const overrideByAccount = Object.fromEntries(
            Object.entries(state.overrideByAccount).map(([accountId, segs]) => [
                accountId,
                segs === undefined ? undefined : replaceSeg(segs),
            ]),
        ) as Record<string, string[] | undefined>;

        dispatch({
            type: 'updateSegments',
            segments: replaceSeg(state.segments),
        });
        for (const [accountId, segs] of Object.entries(overrideByAccount)) {
            if (segs !== undefined) {
                dispatch({
                    type: 'setOverrideSegments',
                    accountId,
                    segments: segs,
                });
            }
        }
        dispatch({
            type: 'setMentions',
            mentions: state.mentions.map((item) =>
                item.id === mention.id ? next : item,
            ),
        });
    }

    function applySavedMention(
        mention: MentionPlaceholder,
        saved: WorkspaceMention,
    ) {
        renameMention(mention, savedMentionToPlaceholder(saved));
    }

    async function saveMention(mention: MentionPlaceholder): Promise<void> {
        saveMentionHttp.transform(() => ({
            name: mention.label,
            handles: mention.handles,
        }));
        const response = await saveMentionHttp.post(
            WorkspaceMentionController.store().url,
        );

        setSavedMentions((current) => {
            const others = current.filter(
                (item) =>
                    item.id !== response.mention.id &&
                    item.name !== response.mention.name,
            );

            return [...others, response.mention].sort((left, right) =>
                left.name.localeCompare(right.name),
            );
        });
    }

    function handleSegments(segments: string[]) {
        const manualSplit = segments.length > 1;
        if (
            activeAccount &&
            state.overrideByAccount[activeAccount.id] !== undefined
        ) {
            const overrideByAccount = {
                ...state.overrideByAccount,
                [activeAccount.id]: segments,
            };
            dispatch({
                type: 'setOverrideSegments',
                accountId: activeAccount.id,
                segments,
            });
            if (manualSplit) {
                dispatch({
                    type: 'disableAutoSplit',
                    accountIds: accounts.map((account) => account.id),
                });
            }
            syncMentions(state.segments, overrideByAccount);

            return;
        }
        dispatch({ type: 'updateSegments', segments });
        if (manualSplit) {
            dispatch({
                type: 'disableAutoSplit',
                accountIds: accounts.map((account) => account.id),
            });
        }
        syncMentions(segments);
    }

    const activeTarget = activeAccount
        ? post?.targets.find((t) => t.connected_account_id === activeAccount.id)
        : undefined;
    const activeSectionTotal = activeTarget?.sections.length ?? 1;
    const overrideActive =
        activeAccount !== null &&
        state.overrideByAccount[activeAccount.id] !== undefined;
    const mentionPlatforms = Array.from(
        new Set(tabAccounts.map((account) => account.platform)),
    );
    const previewAccount = activeAccount ?? null;
    const platformPreview = previewAccount
        ? buildPlatformPreview({
              account: previewAccount,
              segments:
                  state.overrideByAccount[previewAccount.id] ?? state.segments,
              mentions: state.mentions,
              media: state.media,
              excludedMediaIds: new Set(
                  state.media
                      .filter((media) =>
                          state.mediaSubsetExcludes.has(
                              `${media.id}:${previewAccount.id}`,
                          ),
                      )
                      .map((media) => media.id),
              ),
              limit: limitForAccount(previewAccount),
              autoSplit: state.autoSplitByAccount[previewAccount.id] ?? true,
          })
        : buildPlatformPreview({
              account: PREVIEW_FALLBACK_ACCOUNT,
              segments: state.segments,
              mentions: state.mentions,
              media: state.media,
              excludedMediaIds: new Set(),
              limit: limitForPlatform('x'),
              autoSplit: true,
          });

    return (
        <div
            className={cn(
                'grid items-start transition-[grid-template-columns,gap] duration-300 ease-out motion-reduce:transition-none',
                previewVisible
                    ? 'gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(340px,420px)]'
                    : 'gap-0 xl:grid-cols-[minmax(0,1fr)_minmax(0px,0px)]',
            )}
        >
            <div className="overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm transition-[box-shadow,border-color] duration-300 focus-within:border-primary/25 focus-within:shadow-[0_0_16px_-6px_color-mix(in_oklch,var(--primary)_28%,transparent)]">
                {/* Tab-strip row */}
                <div className="flex items-center border-b border-border px-2 py-2">
                    {/* Tabs hang to the bottom border (underline meets it) via a
                    negative margin that cancels the row's bottom padding, while
                    the right-side controls stay vertically centered in the bar. */}
                    <div className="-mb-2 flex min-w-0 flex-1 items-end">
                        <PlatformTabs
                            accounts={tabAccounts}
                            activeTab={activeAccount?.id ?? state.activeTab}
                            onChange={(tab) =>
                                dispatch({ type: 'setActiveTab', tab })
                            }
                            chipFor={chipFor}
                            stateFor={severityFor}
                            hasOverride={(accountId) =>
                                state.overrideByAccount[accountId] !== undefined
                            }
                        />
                    </div>
                    <div className="ml-auto flex items-center gap-2 pr-1">
                        <div
                            className="inline-flex h-7 overflow-hidden rounded-md border border-transparent text-[12px] text-muted-foreground data-[active=true]:border-border data-[active=true]:bg-background data-[active=true]:text-foreground"
                            data-active={previewVisible}
                        >
                            <button
                                type="button"
                                aria-label="Toggle platform preview"
                                aria-pressed={previewVisible}
                                onClick={() => setShowPreview((open) => !open)}
                                className="inline-flex items-center gap-1.5 px-2 hover:bg-muted hover:text-foreground"
                            >
                                <Eye className="size-3.5 shrink-0" />
                                <span>Preview</span>
                            </button>
                            <button
                                type="button"
                                aria-label="Pin platform preview"
                                aria-pressed={previewPinned}
                                data-active={previewPinned}
                                onClick={() =>
                                    setPreviewPinned((pinned) => !pinned)
                                }
                                className="inline-flex w-6 items-center justify-center border-l border-border/70 hover:bg-muted hover:text-foreground data-[active=true]:bg-primary/10 data-[active=true]:text-primary"
                            >
                                <Pin className="size-3.5 shrink-0" />
                            </button>
                        </div>
                        <DestinationSelector
                            accounts={accounts}
                            sets={sets}
                            destination={state.destination}
                            disabled={readOnly}
                            onChange={(destination) =>
                                dispatch({
                                    type: 'setDestination',
                                    destination,
                                })
                            }
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
                    value={activeSegments}
                    onChange={handleSegments}
                    onBlur={flush}
                    editable={!readOnly}
                    autoFocus={autoFocusEditor}
                    onPasteFiles={readOnly ? undefined : handleAddedFiles}
                    overrideBanner={overrideActive}
                    activePlatformLabel={activeAccount?.platform ?? null}
                    onResetOverride={() =>
                        activeAccount &&
                        dispatch({
                            type: 'discardOverride',
                            accountId: activeAccount.id,
                        })
                    }
                    mentions={state.mentions}
                    mentionPlatforms={mentionPlatforms}
                    savedMentions={savedMentions}
                    onMentionNameChange={renameMention}
                    onApplySavedMention={applySavedMention}
                    onSaveMention={saveMention}
                    saveMentionProcessing={saveMentionHttp.processing}
                    onMentionsChange={(mentions) =>
                        dispatch({ type: 'setMentions', mentions })
                    }
                    markerState={
                        activeAccount
                            ? {
                                  platform: activeAccount.platform,
                                  autoSplit:
                                      state.autoSplitByAccount[
                                          activeAccount.id
                                      ] ?? true,
                                  limit: limitForAccount(activeAccount),
                                  threadMax:
                                      limits.find(
                                          (l) =>
                                              l.platform ===
                                              activeAccount.platform,
                                      )?.threadMax ?? null,
                              }
                            : undefined
                    }
                />

                {/* Counter row — or the connect prompt when there are no accounts. */}
                {activeAccount ? (
                    <CharCounter
                        count={measure(
                            replaceMentionTokens(
                                activeSegments.join('\n'),
                                state.mentions,
                                activeAccount.platform,
                            ),
                            activeAccount.platform,
                        )}
                        limit={limitForAccount(activeAccount)}
                        sectionTotal={activeSectionTotal}
                        state={severityFor(activeAccount.id)}
                    />
                ) : showConnectAccountPrompt ? (
                    <div className="px-4 pb-3.5 sm:px-[26px]">
                        <Link
                            href={accountsRoute().url}
                            className="inline-flex items-center gap-1.5 rounded-md border border-dashed border-border px-2.5 py-1 text-[12px] tracking-[-0.005em] text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-foreground"
                        >
                            <Plug className="size-3.5" aria-hidden />
                            Connect an account to publish
                        </Link>
                    </div>
                ) : null}

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
                        onRemove={(id) =>
                            dispatch({ type: 'removeMedia', mediaId: id })
                        }
                        onReorder={(ids) =>
                            dispatch({ type: 'reorderMedia', ids })
                        }
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
                                    type: 'setOverrideSegments',
                                    accountId: activeAccount.id,
                                    segments: state.segments,
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
                        pending={mediaUploads.pending}
                        handleFiles={handleAddedFiles}
                        dismissPending={mediaUploads.dismissPending}
                        onImageClick={openImage}
                        onVideoClick={openVideo}
                    />
                )}

                {!readOnly && (
                    <ImageEditor
                        open={
                            editing !== null &&
                            editing.kind !== 'video' &&
                            editing.kind !== 'video-new'
                        }
                        sourceUrl={editorSourceUrl}
                        initialSettings={editorSettings}
                        initialAltText={editorAltText}
                        onApply={applyEditing}
                        onCancel={cancelEditing}
                        onDiscard={discardEditing}
                        variant={editing?.kind === 'batch' ? 'new' : 'existing'}
                        isSaving={imageEditor.isSaving}
                        queue={editorQueue}
                    />
                )}

                {!readOnly && (
                    <VideoEditor
                        open={
                            editing?.kind === 'video' ||
                            editing?.kind === 'video-new'
                        }
                        variant={
                            editing?.kind === 'video-new' ? 'new' : 'existing'
                        }
                        sourceUrl={
                            editing?.kind === 'video' ||
                            editing?.kind === 'video-new'
                                ? editing.url
                                : null
                        }
                        durationSeconds={
                            editing?.kind === 'video' ||
                            editing?.kind === 'video-new'
                                ? editing.durationSeconds
                                : 0
                        }
                        phase={videoEditor.phase}
                        progress={videoEditor.progress}
                        initialAltText={
                            editing?.kind === 'video' ? editing.altText : null
                        }
                        onCancel={closeVideoEditing}
                        onSkip={() => {
                            if (editing?.kind !== 'video-new') {
                                return;
                            }
                            void mediaUploads.handleFiles([editing.file]);
                            closeVideoEditing();
                        }}
                        onApply={async (settings, altText) => {
                            if (
                                editing?.kind !== 'video' &&
                                editing?.kind !== 'video-new'
                            ) {
                                return;
                            }
                            try {
                                const source =
                                    editing.kind === 'video-new'
                                        ? editing.file
                                        : await fetch(editing.url).then((r) =>
                                              r.blob(),
                                          );
                                const oldMediaId =
                                    editing.kind === 'video'
                                        ? editing.mediaId
                                        : null;
                                const ok = await videoEditor.apply({
                                    source,
                                    oldMediaId,
                                    settings,
                                    altText,
                                    limits: selectedVideoLimits,
                                });
                                if (ok) {
                                    closeVideoEditing();
                                }
                            } catch {
                                toast.error(
                                    'Could not load the video to edit. Please try again.',
                                );
                            }
                        }}
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
                            attentionHandles={attentionAccounts.map(
                                (account) => account.handle,
                            )}
                            queueDisabled={queueState.status !== 'found'}
                            uploading={mediaUploads.isUploading}
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
                        myBaseText={state.segments.join('\n')}
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

            {/* Collapsible preview. The outer grid track animates the editor's
            width on xl; this column collapses its own height (grid-rows 1fr↔0fr)
            so a hidden preview reclaims its space instead of leaving a gap. The
            card keeps a stable height via xl:min-w while it wipes, and sticky
            lives on the wrapper so it still pins to the editor row. */}
            <div className="xl:sticky xl:top-20" aria-hidden={!previewVisible}>
                <div
                    className={cn(
                        'grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none',
                        previewVisible
                            ? 'grid-rows-[1fr] opacity-100'
                            : 'grid-rows-[0fr] opacity-0',
                    )}
                >
                    <div className="min-h-0 w-full overflow-hidden">
                        <div className="xl:min-w-[340px]">
                            <PlatformPreviewPanel preview={platformPreview} />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export { BASE_TAB };
