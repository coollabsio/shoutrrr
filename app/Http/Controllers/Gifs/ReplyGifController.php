<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gifs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gifs\AttachGifRequest;
use App\Jobs\TriggerKlipyShare;
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
        // there is no server-side "media already on this reply" to query.
        // ReplyMediaController::store() reflects the same reality — a reply's
        // media list lives only in client state (quick-reply-box.tsx) until the
        // reply is sent, so there is nothing existing to guard against here.
        try {
            $media = $this->attacher->attach(
                $reply->workspace_id,
                $validated['catalog'],
                (string) ($validated['title'] ?? ''),
                $validated['variants'],
                [],
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
