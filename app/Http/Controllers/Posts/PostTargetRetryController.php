<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostTargetStatus;
use App\Http\Controllers\Controller;
use App\Jobs\PublishPostTarget;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\PostStatusRollup;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostTargetRetryController extends Controller
{
    public function store(Request $request, Post $post, PostTarget $target): JsonResponse
    {
        abort_unless($request->user()->can('update', $post), 403);
        abort_unless($target->status === PostTargetStatus::Failed, 409);

        $target->forceFill([
            'status' => PostTargetStatus::Pending->value,
            'error_kind' => null,
            'error_message' => null,
            'next_attempt_at' => null,
        ])->save();

        PublishPostTarget::dispatch($target);

        // Reflect the in-flight retry on the post status immediately.
        app(PostStatusRollup::class)->recompute($post);

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }
}
