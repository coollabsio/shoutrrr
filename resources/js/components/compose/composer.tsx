import { Link, useHttp, usePage } from '@inertiajs/react';
import { useEffect, useReducer, useRef, useState } from 'react';
import { toast } from 'sonner';

import PostGifController from '@/actions/App/Http/Controllers/Gifs/PostGifController';
import WorkspaceMentionController from '@/actions/App/Http/Controllers/WorkspaceMentionController';
import { useConfirm } from '@/components/common/confirm-dialog';
import { AtSign, Eye, Pin, Plug, TriangleAlert } from '@/components/ui/icons';
import { useAutosave } from '@/hooks/compose/use-autosave';
import { useEmojiPreferences } from '@/hooks/compose/use-emoji-preferences';
import { useImageEditor } from '@/hooks/compose/use-image-editor';
import { useMediaUploads } from '@/hooks/compose/use-media-uploads';
import { useNextSlot } from '@/hooks/compose/use-next-slot';
import { usePublishStatus } from '@/hooks/compose/use-publish-status';
import { useVideoEditor } from '@/hooks/compose/use-video-editor';
import { useSchedulingTimezone } from '@/hooks/posts/use-scheduling-timezone';
import {
    composerReducer,
    hasMultipleThreads,
    initialComposerState,
    pickActiveAccount,
    shouldShowConnectAccountPrompt,
    type ComposerState,
} from '@/lib/compose/composer-state';
import {
    describeFormatNotice,
    precheckNotices,
} from '@/lib/compose/format-notices';
import { postGifAttachment } from '@/lib/compose/gifs/attach';
import {
    isAttachOnlyImage,
    wouldMixVideoAndImages,
    wouldViolateBlueskyGif,
} from '@/lib/compose/media-rules';
import {
    replaceMentionLabel,
    replaceMentionTokens,
    savedMentionToPlaceholder,
    syncMentionsFromText,
} from '@/lib/compose/mentions';
import { buildPlatformPreview } from '@/lib/compose/platform-preview';
import { precheckAccount, precheckDestinations } from '@/lib/compose/precheck';
import { readVideoMetadata, videoLimitsForTargets } from '@/lib/compose/video';
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
    type MediaView,
    type MentionPlaceholder,
    type PlatformLimits,
    type PlatformName,
    type PostView,
    type WorkspaceMention,
} from '@/types/compose';
import type { GifItem } from '@/types/gifs';

import CharCounter from './char-counter';
import { ComposerToolbar } from './composer-toolbar';
import { ConflictDialog } from './conflict-dialog';
import DestinationSelector from './destination-selector';
import { GoogleBusinessProfileOptions } from './google-business-profile-options';
import EditorBody, { type EditorBodyHandle } from './editor-body';
import { ImageEditor } from './image-editor';
import { PlatformPreviewPanel } from './platform-preview-panel';
import PlatformTabs from './platform-tabs';
import SaveIndicator from './save-indicator';
import { ScheduleTray } from './schedule-tray';
import { SegmentMediaRow } from './segment-media-row';
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

/** Platforms the auto-repost backfeature supports (Task 12). */
const REPOST_CAPABLE_PLATFORMS = new Set<PlatformName>([
    'x',
    'linkedin',
    'bluesky',
]);

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
    /** Fired after each successful autosave (create or update). */
    onSaved?: () => void;
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
    if (destination.kind === 'none') {
        return [];
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
    onSaved,
}: ComposerProps) {
    const schedulingTz = useSchedulingTimezone();
    const confirm = useConfirm();
    const { shell } = usePage().props;
    const saveMentionHttp = useHttp<
        Record<string, never>,
        { mention: WorkspaceMention }
    >({});
    const deleteMentionHttp = useHttp<Record<string, never>, unknown>({});
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
    const repostAccounts = tabAccounts.filter((account) =>
        REPOST_CAPABLE_PLATFORMS.has(account.platform),
    );
    const selectedVideoLimits = videoLimitsForTargets(limits, tabAccounts);
    const { flush, ensurePost } = useAutosave({
        state,
        accountIds: destinationAccountIds,
        dispatch,
        onSaved,
    });
    const publishStatus = usePublishStatus({ pagePost: post });

    const explicitUploadSegmentRef = useRef<string | null>(null);
    const segmentFileInputRef = useRef<HTMLInputElement | null>(null);

    // Resolves which segment a just-finished upload/edit should attach to: an
    // explicit per-segment target (see `addMediaToSegment`), held for the
    // *entire* upload+editor session — not just until the file picker's
    // promise settles — else the caret's current segment, else the head.
    function resolveTargetSegmentRef(): string {
        return (
            explicitUploadSegmentRef.current ??
            editorRef.current?.activeSegmentRef() ??
            '__head__'
        );
    }

    // Owns the media-upload pipeline (image/video validation + upload). Lifted
    // here so both the editor (⌘/Ctrl+V paste) and the toolbar (picker/drop)
    // feed the same handleFiles and share one in-flight `pending` list.
    const mediaUploads = useMediaUploads({
        mediaForSegment,
        videoLimits: selectedVideoLimits,
        onEnsurePost: ensurePost,
        onAddMedia: (m, segmentRef) =>
            dispatch({ type: 'addMedia', media: m, segmentRef }),
        activeSegmentRef: resolveTargetSegmentRef,
    });

    const imageEditor = useImageEditor({
        onEnsurePost: ensurePost,
        onAddMedia: (m) =>
            dispatch({
                type: 'addMedia',
                media: m,
                segmentRef: resolveTargetSegmentRef(),
            }),
        onReplaceMedia: (m) => dispatch({ type: 'replaceMedia', media: m }),
    });

    const videoEditor = useVideoEditor({
        onEnsurePost: ensurePost,
        onComplete: (oldMediaId, media) => {
            dispatch({
                type: 'addMedia',
                media,
                segmentRef: resolveTargetSegmentRef(),
            });
            if (oldMediaId) {
                dispatch({ type: 'removeMedia', mediaId: oldMediaId });
            }
        },
    });

    // The toolbar's Emoji picker inserts through this handle so it reaches the
    // editor's live selection without lifting TipTap state into the reducer.
    const editorRef = useRef<EditorBodyHandle>(null);
    const emojiPrefs = useEmojiPreferences();

    function insertEmoji(emoji: string) {
        editorRef.current?.insertText(emoji);
        emojiPrefs.addRecent(emoji);
    }

    // The server downloads and re-hosts the chosen GIF, so this is a chip +
    // fetch rather than the local upload flow the other media handlers use.
    // `targetSegmentRef` lets a segment row's own GIF button target that
    // segment explicitly; the global toolbar button omits it and falls back
    // to the caret's segment.
    async function attachGif(item: GifItem, targetSegmentRef?: string) {
        // Frozen before the `ensurePost` await, which can round-trip to the
        // server, so the GIF lands where it was targeted at selection time
        // even if the caret moves while the draft post is being created.
        const segmentRef =
            targetSegmentRef ??
            editorRef.current?.activeSegmentRef() ??
            '__head__';
        const postId = await ensurePost();
        if (!postId) {
            return;
        }

        await mediaUploads.trackPending(
            {
                kind: item.catalog === 'clip' ? 'video' : 'image',
                previewUrl: item.preview.url,
            },
            () =>
                postGifAttachment(
                    PostGifController.store.url({ post: postId }),
                    item,
                    // Declare only the *target segment's* media, not the whole
                    // post's: the mixing-rule guard is per-segment, so a GIF in
                    // one thread must not be blocked by media in another. Draft
                    // rows also stay orphaned (`post_id` null) until the next
                    // save, so this client-declared set is what the guard checks.
                    mediaForSegment(segmentRef).map((m) => m.id),
                ),
            segmentRef,
        );
    }

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
        // The editing session (which may span several batch items) is over —
        // only now is it safe to release the explicit per-segment target, since
        // `imageEditor.onAddMedia` reads it for every apply along the way.
        explicitUploadSegmentRef.current = null;
        setEditing(null);
    }

    // Set synchronously in lockstep with every `setEditing(...)` call below
    // that opens a genuine editing session for freshly-picked files. Reading
    // `editing`/`editingRef` back from `acceptSegmentFiles`'s `.finally()`
    // isn't reliable there — that ref only tracks the *rendered* state, and a
    // promise microtask isn't guaranteed to run after React has re-rendered
    // in response to the `setEditing` call in the same tick. This ref sidesteps
    // that entirely by being written directly, independent of any render.
    const openedEditorRef = useRef(false);

    // Split a picked/dropped/pasted batch: videos upload directly; images open
    // the editor as a batch (edited one at a time).
    async function handleAddedFiles(files: FileList | File[]): Promise<void> {
        openedEditorRef.current = false;
        const all = Array.from(files);

        // Bluesky publishes a GIF as video and allows only one, unmixed. Block it
        // up front (same as the one-video rule) so it never reaches publishing —
        // only when a Bluesky account is a target, since LinkedIn allows GIF+images.
        const targetsBluesky = tabAccounts.some(
            (a) => a.platform === 'bluesky',
        );
        if (
            targetsBluesky &&
            wouldViolateBlueskyGif(
                mediaForSegment(resolveTargetSegmentRef()),
                all,
            )
        ) {
            toast.error(
                'Bluesky supports one animated GIF per post, and it cannot be mixed with other media.',
            );

            return;
        }

        const videos = all.filter((f) => f.type.startsWith('video/'));
        // Anything that isn't a video is treated as an image: some clipboard
        // pastes report an empty/unknown MIME type, and the server validates the
        // real content type on upload.
        const images = all.filter((f) => !f.type.startsWith('video/'));

        // A post is one video OR images, never both — decide before uploading
        // anything, so a mixed drop doesn't half-attach the video.
        if (
            wouldMixVideoAndImages(
                mediaForSegment(resolveTargetSegmentRef()),
                all,
            )
        ) {
            toast.error('A post can contain one video or images, not both.');

            return;
        }

        if (videos.length > 0) {
            const file = videos[0];
            try {
                const meta = await readVideoMetadata(file);
                openedEditorRef.current = true;
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

        // GIFs skip the beautifier: it composes to a static PNG, which would strip
        // the animation. Upload them as-is; the rest open the editor as a batch.
        const gifs = images.filter((f) => f.type === 'image/gif');
        const editable = images.filter((f) => f.type !== 'image/gif');

        if (gifs.length > 0) {
            void mediaUploads.handleFiles(gifs);
        }

        if (editable.length === 0) {
            return;
        }
        openedEditorRef.current = true;
        setEditing({
            kind: 'batch',
            items: editable.map((f) => ({
                file: f,
                url: URL.createObjectURL(f),
            })),
            index: 0,
        });
    }

    // A segment's "add media" affordance: target that segment for whatever
    // gets picked next, then open the shared file picker. Images/videos don't
    // attach immediately — they open the beautifier/trim editor first — so the
    // override must survive that whole session; it's only released once the
    // editor actually closes (see `endEditingStep` / `closeVideoEditing`), or
    // right here if nothing ended up opening an editor (e.g. a GIF-only pick,
    // a validation rejection, or an empty selection).
    function addMediaToSegment(segmentRef: string) {
        explicitUploadSegmentRef.current = segmentRef;
        segmentFileInputRef.current?.click();
    }

    function acceptSegmentFiles(files: FileList) {
        void handleAddedFiles(files).finally(() => {
            if (segmentFileInputRef.current) {
                segmentFileInputRef.current.value = '';
            }
            // If nothing opened an editor (a GIF-only pick, a validation
            // rejection, or an empty selection), there's no later apply/cancel
            // to release the target on — clear it now. Otherwise leave it for
            // `endEditingStep`/`closeVideoEditing` to release once that
            // session actually ends.
            if (!openedEditorRef.current) {
                explicitUploadSegmentRef.current = null;
            }
        });
    }

    // Revoke the object URL for a video-new session and close the editor.
    function closeVideoEditing() {
        if (editing?.kind === 'video-new') {
            URL.revokeObjectURL(editing.url);
        }
        explicitUploadSegmentRef.current = null;
        setEditing(null);
    }

    // Find the segment an already-attached media id currently lives in, so
    // re-opening its editor can pin the eventual apply back to that same
    // segment (see `addMediaToSegment`) instead of falling through to
    // whatever segment the caret happens to be in when the edit completes.
    function segmentRefForMedia(mediaId: string): string {
        const entry = Object.entries(activeScopePlacements).find(([, ids]) =>
            ids.includes(mediaId),
        );

        return entry?.[0] ?? '__head__';
    }

    // Open an attached video in the video editor.
    function openVideo(mediaId: string) {
        const m = state.media.find((x) => x.id === mediaId);
        if (!m || m.kind !== 'video') {
            return;
        }
        // Trimming mints a new media id (see `videoEditor.onComplete`) that
        // must land back in this video's own segment, not wherever the caret
        // is — pin the target before opening the editor.
        explicitUploadSegmentRef.current = segmentRefForMedia(mediaId);
        setEditing({
            kind: 'video',
            url: m.edit_url,
            durationSeconds: m.duration_seconds ?? 0,
            mediaId: m.id,
            altText: m.alt_text,
        });
    }

    // Re-open an attached image: a beautified one rehydrates from its persisted
    // source + settings; a plain one is beautified from scratch.
    function openImage(mediaId: string) {
        const m = state.media.find((x) => x.id === mediaId);
        // Animated images (GIF, or a GIF-browser WebP) have no editor — the
        // beautifier would flatten them to a still frame.
        if (!m || m.kind === 'video' || isAttachOnlyImage(m)) {
            return;
        }
        // First-time beautify (the `raw` branch below) mints a new media id
        // via `imageEditor.applyNew`, which must land back in this image's
        // own segment rather than the caret's — pin the target before
        // opening the editor. (`reedit` preserves the id via `applyEdit` and
        // ignores this target, but setting it here is harmless and keeps the
        // two branches symmetric.)
        explicitUploadSegmentRef.current = segmentRefForMedia(mediaId);
        if (m.edit_settings && m.source_url) {
            setEditing({
                kind: 'reedit',
                url: m.source_edit_url ?? m.edit_url,
                settings: normalizeSettings(m.edit_settings),
                mediaId: m.id,
                altText: m.alt_text,
            });
        } else {
            setEditing({ kind: 'raw', url: m.edit_url, mediaId: m.id });
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
    const activeHasOverride =
        activeAccount !== null &&
        state.overrideByAccount[activeAccount.id] !== undefined;

    // Media-placement edits (move/remove/reorder) diverge onto the active
    // account only when that account is being customized: it has an explicit
    // content override, or already carries account-specific placements (e.g.
    // hydrated from an already-diverged post). Otherwise edits stay canonical
    // (shared across every account), mirroring how text overrides gate
    // per-account text divergence. `undefined` = canonical scope.
    const divergeAccountId =
        activeAccount &&
        (activeHasOverride ||
            state.placementsByAccount[activeAccount.id] !== undefined)
            ? activeAccount.id
            : undefined;

    // The per-segment media rows (portaled into the editor by segment ref) read
    // placements from the active scope: the diverging account's own copy, else
    // canonical.
    const activeScopePlacements =
        divergeAccountId && state.placementsByAccount[divergeAccountId]
            ? state.placementsByAccount[divergeAccountId]
            : state.placements;

    function mediaForSegment(segmentRef: string): MediaView[] {
        const ids = activeScopePlacements[segmentRef] ?? [];

        return ids
            .map((id) => state.media.find((m) => m.id === id))
            .filter((m): m is MediaView => m !== undefined);
    }

    // With a single thread, the global toolbar's "add media" button already
    // attaches to it, so the per-segment hover-reveal row is redundant — it
    // only earns its keep once there's more than one thread to choose between.
    const singleThread = !hasMultipleThreads(state);

    // An account override can carry more thread posts than the canonical
    // `segmentBreaks` structure tracks (its own manual splits diverge from the
    // shared thread shape). Placements are always keyed by the *canonical*
    // break refs, so a segment past that range has no ref the server's
    // `mediaSegmentsFromPlacements` recognizes — media added there would
    // silently fail to persist on save. Hide the per-segment "add media" row
    // for the whole account in that case, the same coarse-grained way
    // `singleThread` hides it above.
    const overrideExceedsSegmentBreaks =
        activeAccount !== null &&
        (state.overrideByAccount[activeAccount.id]?.length ?? 0) >
            state.segmentBreaks.length + 1;

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
        const platformLimits = limits.find(
            (l) => l.platform === account.platform,
        );
        if (!platformLimits) {
            return 'ok';
        }
        const segments =
            state.overrideByAccount[accountId] !== undefined
                ? (state.overrideByAccount[accountId] as string[])
                : state.segments;
        // Whole-post media count — the server precheck and connectors still
        // enforce media caps per whole post (see precheckDestinations), so the
        // severity a destination shows is judged against the full media set.
        const mediaCount = state.media.length;
        const hasVideo = state.media.some((item) => item.kind === 'video');
        const reasons = precheckAccount({
            account,
            segments,
            autoSplit: state.autoSplitByAccount[accountId] ?? true,
            mentions: state.mentions,
            mediaCount,
            hasVideo,
            format: state.formatByAccount[accountId] ?? 'feed',
            limits: platformLimits,
        });
        if (reasons.length > 0) {
            return 'over';
        }
        const resolvedText = replaceMentionTokens(
            segments.join('\n'),
            state.mentions,
            account.platform,
        );
        const limit = limitForAccount(account);
        const count = measure(resolvedText, account.platform);

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

    async function deleteMention(saved: WorkspaceMention): Promise<void> {
        const confirmed = await confirm({
            title: `Delete ${saved.name}?`,
            description:
                'This removes the saved mention from your workspace library. Mentions already added to posts are unaffected.',
            actionLabel: 'Delete mention',
            destructive: true,
        });

        if (!confirmed) {
            return;
        }

        await deleteMentionHttp.delete(
            WorkspaceMentionController.destroy(saved.id).url,
        );

        setSavedMentions((current) =>
            current.filter((item) => item.id !== saved.id),
        );
    }

    function handleSegments(segments: string[], breakIds: string[]) {
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
        // Overrides carry their own text but not their own thread structure —
        // segment refs (and therefore per-segment media placement) always
        // track the canonical break ids, so only update them here.
        if (JSON.stringify(breakIds) !== JSON.stringify(state.segmentBreaks)) {
            dispatch({ type: 'setSegmentBreaks', breakIds });
        }
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
              // Per-account media divergence now lives in placements (Task 15),
              // not a global exclude set.
              excludedMediaIds: new Set<string>(),
              limit: limitForAccount(previewAccount),
              autoSplit: state.autoSplitByAccount[previewAccount.id] ?? true,
              format: state.formatByAccount[previewAccount.id] ?? 'feed',
              // Show each media under the thread post it's placed on, using the
              // previewed account's scope (diverged copy, else canonical).
              placements:
                  state.placementsByAccount[previewAccount.id] ??
                  state.placements,
              segmentBreaks: state.segmentBreaks,
          })
        : buildPlatformPreview({
              account: PREVIEW_FALLBACK_ACCOUNT,
              segments: state.segments,
              mentions: state.mentions,
              media: state.media,
              excludedMediaIds: new Set(),
              limit: limitForPlatform('x'),
              autoSplit: true,
              format: 'feed',
              placements: state.placements,
              segmentBreaks: state.segmentBreaks,
          });

    const blockedAccounts = precheckDestinations({
        accounts: tabAccounts,
        segments: state.segments,
        mentions: state.mentions,
        autoSplitByAccount: state.autoSplitByAccount,
        overrideByAccount: state.overrideByAccount,
        formatByAccount: state.formatByAccount,
        providerOptionsByAccount: state.providerOptionsByAccount,
        media: state.media,
        limits,
    });
    const notices = precheckNotices({
        accounts: tabAccounts,
        segments: state.segments,
        overrideByAccount: state.overrideByAccount,
        formatByAccount: state.formatByAccount,
        media: state.media,
    });
    const activeNotices = activeAccount
        ? (notices.find((n) => n.accountId === activeAccount.id)?.notices ?? [])
        : [];

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
                <div className="flex flex-wrap items-center gap-y-2 border-b border-border px-2 py-2 md:flex-nowrap md:gap-y-0">
                    {/* Tabs hang to the bottom border (underline meets it) via a
                    negative margin that cancels the row's bottom padding, while
                    the right-side controls stay vertically centered in the bar. */}
                    <div className="-mb-2 flex min-w-0 flex-[1_1_100%] items-end md:flex-1">
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
                            noticeAccountIds={notices.map(
                                (notice) => notice.accountId,
                            )}
                        />
                    </div>
                    <div className="ml-auto flex min-w-0 items-center justify-end gap-1.5 pr-1 sm:gap-2">
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
                                <span className="hidden sm:inline">
                                    Preview
                                </span>
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
                    ref={editorRef}
                    value={activeSegments}
                    breakIds={state.segmentBreaks}
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
                    onDeleteMention={deleteMention}
                    onMentionsChange={(mentions) =>
                        dispatch({ type: 'setMentions', mentions })
                    }
                    emojiSkinTone={emojiPrefs.skinTone}
                    onEmojiInsert={emojiPrefs.addRecent}
                    renderSegmentMedia={
                        readOnly && state.media.length === 0
                            ? undefined
                            : (ref) => {
                                  const segMedia = mediaForSegment(ref);
                                  const segPending =
                                      mediaUploads.pending.filter(
                                          (p) => p.segmentRef === ref,
                                      );
                                  if (
                                      readOnly &&
                                      segMedia.length === 0 &&
                                      segPending.length === 0
                                  ) {
                                      return null;
                                  }
                                  if (
                                      (singleThread ||
                                          overrideExceedsSegmentBreaks) &&
                                      segMedia.length === 0 &&
                                      segPending.length === 0
                                  ) {
                                      return null;
                                  }

                                  return (
                                      <SegmentMediaRow
                                          segmentRef={ref}
                                          media={segMedia}
                                          pending={segPending}
                                          readOnly={readOnly}
                                          onRemove={(mediaId) =>
                                              dispatch({
                                                  type: 'removeMediaFromSegments',
                                                  mediaId,
                                                  accountId: divergeAccountId,
                                              })
                                          }
                                          onReorder={(ids) =>
                                              dispatch({
                                                  type: 'reorderSegmentMedia',
                                                  segmentRef: ref,
                                                  ids,
                                                  accountId: divergeAccountId,
                                              })
                                          }
                                          onDropMedia={(mediaId, targetRef) =>
                                              dispatch({
                                                  type: 'moveMediaToSegment',
                                                  mediaId,
                                                  segmentRef: targetRef,
                                                  accountId: divergeAccountId,
                                              })
                                          }
                                          onImageClick={openImage}
                                          onVideoClick={openVideo}
                                          onAddClick={() =>
                                              addMediaToSegment(ref)
                                          }
                                          onAttachGif={
                                              shell.gifs_enabled
                                                  ? (item) =>
                                                        attachGif(item, ref)
                                                  : undefined
                                          }
                                          onDismissPending={
                                              mediaUploads.dismissPending
                                          }
                                          onCancelPending={
                                              mediaUploads.cancelPending
                                          }
                                      />
                                  );
                              }
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

                {!readOnly && (
                    <input
                        ref={segmentFileInputRef}
                        type="file"
                        accept={
                            state.media.some((m) => m.kind === 'video')
                                ? 'image/*'
                                : 'image/*,video/*'
                        }
                        multiple
                        hidden
                        onChange={(e) => {
                            if (e.target.files && e.target.files.length > 0) {
                                acceptSegmentFiles(e.target.files);
                            } else {
                                explicitUploadSegmentRef.current = null;
                            }
                        }}
                    />
                )}

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
                ) : destinationAccountIds.length === 0 ? (
                    <div className="px-4 pb-3.5 sm:px-[26px]">
                        <span className="inline-flex items-center gap-1.5 rounded-md border border-dashed border-border px-2.5 py-1 text-[12px] tracking-[-0.005em] text-muted-foreground">
                            <AtSign className="size-3.5" aria-hidden />
                            Select at least one account to publish
                        </span>
                    </div>
                ) : null}

                {activeAccount && activeNotices.length > 0 && (
                    <div className="-mt-1 space-y-1 px-4 pb-3.5 sm:px-[26px]">
                        {activeNotices.map((notice) => (
                            <p
                                key={notice}
                                className="flex items-start gap-1.5 text-[12px] text-amber-700 dark:text-amber-500"
                            >
                                <TriangleAlert
                                    className="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>
                                    {describeFormatNotice(
                                        notice,
                                        activeAccount.platform,
                                    )}
                                </span>
                            </p>
                        ))}
                    </div>
                )}

                {/* Toolbar — editing controls when editable; just the attached
                media when read-only (skipped entirely if there's none). */}
                {(!readOnly || state.media.length > 0) && (
                    <ComposerToolbar
                        readOnly={readOnly}
                        onInsertEmoji={insertEmoji}
                        emojiRecents={emojiPrefs.recents}
                        emojiSkinTone={emojiPrefs.skinTone}
                        onEmojiSkinToneChange={emojiPrefs.setSkinTone}
                        onAttachGif={shell.gifs_enabled ? attachGif : undefined}
                        activePlatform={activeAccount?.platform}
                        autoSplit={
                            activeAccount
                                ? (state.autoSplitByAccount[activeAccount.id] ??
                                  true)
                                : false
                        }
                        format={
                            activeAccount
                                ? (state.formatByAccount[activeAccount.id] ??
                                  'feed')
                                : 'feed'
                        }
                        onFormatChange={
                            activeAccount
                                ? (format) =>
                                      dispatch({
                                          type: 'setFormat',
                                          accountId: activeAccount.id,
                                          format,
                                      })
                                : undefined
                        }
                        overrideActive={overrideActive}
                        showSplitControls={activeAccount !== null}
                        boost={
                            repostAccounts.length > 0
                                ? {
                                      value: state.autoRepost,
                                      onChange: (value) =>
                                          dispatch({
                                              type: 'setAutoRepost',
                                              value,
                                          }),
                                      accounts: repostAccounts,
                                  }
                                : undefined
                        }
                        media={state.media}
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
                        pending={mediaUploads.pending}
                        handleFiles={handleAddedFiles}
                    />
                )}

                {activeAccount?.platform === 'google_business_profile' && (
                    <GoogleBusinessProfileOptions
                        value={state.providerOptionsByAccount[activeAccount.id]}
                        disabled={readOnly}
                        onChange={(options) =>
                            dispatch({
                                type: 'setGoogleBusinessProfileOptions',
                                accountId: activeAccount.id,
                                options,
                            })
                        }
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
                            disabled={
                                accounts.length === 0 ||
                                destinationAccountIds.length === 0
                            }
                            attentionHandles={attentionAccounts.map(
                                (account) => account.handle,
                            )}
                            queueDisabled={queueState.status !== 'found'}
                            uploading={mediaUploads.isUploading}
                            onSaveDraft={flush}
                            onEnsurePost={ensurePost}
                            onOptimisticSubmit={publishStatus.applyOptimistic}
                            onServerPost={publishStatus.applyServerPost}
                            blockedAccounts={blockedAccounts}
                            limits={limits}
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
                            <PlatformPreviewPanel
                                preview={platformPreview}
                                googleBusinessProfileOptions={
                                    previewAccount?.platform ===
                                    'google_business_profile'
                                        ? state.providerOptionsByAccount[
                                              previewAccount.id
                                          ]
                                        : undefined
                                }
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export { BASE_TAB };
