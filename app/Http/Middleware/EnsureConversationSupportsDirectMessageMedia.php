<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Conversation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404s the conversation-media endpoints when the platform cannot carry a DM
 * attachment (today: Bluesky). Not in `Route::bind('conversation', ...)` — that
 * binder is shared with index/thread/read/archive/respond, which Bluesky needs.
 */
class EnsureConversationSupportsDirectMessageMedia
{
    public function handle(Request $request, Closure $next): Response
    {
        $conversation = $request->route('conversation');

        abort_unless(
            $conversation instanceof Conversation && $conversation->platform->supportsDirectMessageMedia(),
            404,
        );

        return $next($request);
    }
}
