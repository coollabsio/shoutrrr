<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gifs;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gifs\AttachGifRequest;
use App\Jobs\TriggerKlipyShare;
use App\Models\Conversation;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\Gifs\GifAttacher;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ConversationGifController extends Controller
{
    public function __construct(private readonly GifAttacher $attacher) {}

    public function store(AttachGifRequest $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validated();

        // post_media has no conversation_id column, so the draft's attachments
        // live in client state and the client tells us which ids count as
        // "existing" for GifAttacher's mixing-rule guard. See ReplyGifController.
        $existing = PostMedia::query()
            ->where('workspace_id', $conversation->workspace_id)
            ->whereIn('id', $validated['media_ids'] ?? [])
            ->get();

        try {
            $media = $this->attacher->attach(
                $conversation->workspace_id,
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
