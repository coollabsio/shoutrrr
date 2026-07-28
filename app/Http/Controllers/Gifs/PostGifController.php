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

        // Composer media rows stay orphaned (`post_id` null) until
        // DraftService::attachMedia() associates them on the next draft save,
        // so media()->get() is empty for an unsaved draft and the mixing-rule
        // guard below would have nothing to check. The composer therefore
        // declares the media ids it currently holds, exactly as the reply box
        // does — re-resolved here scoped to the post's workspace, so the ids
        // cannot reach media the caller has no access to. Merged with the
        // saved relation and de-duplicated, since a saved draft has both.
        $declared = PostMedia::query()
            ->where('workspace_id', $post->workspace_id)
            ->whereIn('id', $validated['media_ids'] ?? [])
            ->get();

        $existing = $post->media()->get()->concat($declared)->unique('id');

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
