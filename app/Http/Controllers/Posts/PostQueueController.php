<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use App\Services\Posts\NextSlotResolver;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostQueueController extends Controller
{
    public function __construct(private readonly NextSlotResolver $resolver) {}

    public function store(Request $request, Post $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('update', $post), 403);

        $workspace = $user->currentWorkspace;
        abort_if($workspace === null, 404);

        $slot = $this->resolver->resolve($workspace);

        if ($slot === null) {
            return response()->json([
                'message' => 'No open posting slot available. Add posting-schedule slots in settings.',
            ], 422);
        }

        $post->scheduled_at = $slot;
        $post->status = PostStatus::Scheduled;
        $post->save();

        return response()->json([
            'post' => PostView::make($post->fresh(['targets.account', 'media'])),
        ]);
    }
}
