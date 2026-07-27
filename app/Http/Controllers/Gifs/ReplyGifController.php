<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gifs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gifs\AttachGifRequest;
use App\Jobs\TriggerKlipyShare;
use App\Models\PostMedia;
use App\Models\PostTargetReply;
use App\Models\User;
use App\Services\Gifs\GifAttacher;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ReplyGifController extends Controller
{
    public function __construct(private readonly GifAttacher $attacher) {}

    public function store(AttachGifRequest $request, PostTargetReply $reply): JsonResponse
    {
        $validated = $request->validated();

        // post_media has no reply_id column: unlike a post's media() relation,
        // there is no server-side "media already on this reply" to query. The
        // reply's media list lives in client state (quick-reply-box.tsx) until
        // the reply is sent, so the client tells us which of its own media ids
        // to treat as "existing" for GifAttacher's mixing-rule guard.
        // Workspace-scoped: an unscoped lookup would let a caller probe another
        // workspace's media by id.
        $existing = PostMedia::query()
            ->where('workspace_id', $reply->workspace_id)
            ->whereIn('id', $validated['media_ids'] ?? [])
            ->get();

        try {
            $media = $this->attacher->attach(
                $reply->workspace_id,
                $validated['catalog'],
                (string) ($validated['title'] ?? ''),
                $validated['variants'],
                $existing,
                isset($validated['duration_seconds']) ? (int) $validated['duration_seconds'] : null,
            );
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        /** @var User $user */
        $user = $request->user();
        TriggerKlipyShare::maybeDispatch($validated['catalog'], $validated['slug'], GifBrowserController::customerId($user));

        return response()->json(['media' => $media->toView()], 201);
    }
}
