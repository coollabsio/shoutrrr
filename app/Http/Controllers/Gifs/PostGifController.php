<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gifs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gifs\AttachGifRequest;
use App\Jobs\TriggerKlipyShare;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\Gifs\GifAttacher;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PostGifController extends Controller
{
    public function __construct(private readonly GifAttacher $attacher) {}

    public function store(AttachGifRequest $request, Post $post): JsonResponse
    {
        abort_unless($post->status->isEditable(), 422, 'This post can no longer be edited.');

        $validated = $request->validated();

        // The mixing rules (one video OR images, a GIF on its own) apply per
        // thread *segment*, not per post — a GIF in one segment must not be
        // blocked by media the user placed in another. Segment membership lives
        // in client state (composer.tsx `mediaForSegment`), so the composer
        // declares exactly the target segment's media ids here, and we scope the
        // guard to those alone rather than to the whole post's media() relation
        // (which spans every segment). This mirrors ReplyGifController /
        // ConversationGifController, whose surfaces have no segments. Ids are
        // re-resolved workspace-scoped so they cannot reach media the caller has
        // no access to; draft rows stay orphaned (`post_id` null) until the next
        // save, so the client-declared set is the only reliable source anyway.
        $existing = PostMedia::query()
            ->where('workspace_id', $post->workspace_id)
            ->whereIn('id', $validated['media_ids'] ?? [])
            ->get();

        try {
            $media = $this->attacher->attach(
                $post->workspace_id,
                $validated['catalog'],
                (string) ($validated['title'] ?? ''),
                $validated['variants'],
                $existing,
                isset($validated['duration_seconds']) ? (int) $validated['duration_seconds'] : null,
            );
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        // Mirrors PostMediaController::store()/MediaStorageService::storeFromUrl():
        // the row is created as an orphan (post_id null). DraftService::attachMedia()
        // associates it with the post when the composer next saves the draft with
        // this media's id in media_ids.
        /** @var User $user */
        $user = $request->user();
        TriggerKlipyShare::maybeDispatch($validated['catalog'], $validated['slug'], GifBrowserController::customerId($user));

        return response()->json(['media' => $media->toView()], 201);
    }
}
