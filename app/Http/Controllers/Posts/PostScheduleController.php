<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Post\SchedulePostRequest;
use App\Models\Post;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class PostScheduleController extends Controller
{
    public function update(SchedulePostRequest $request, Post $post, WorkspaceSubscriptionGate $subscriptions): JsonResponse|RedirectResponse
    {
        $workspace = $post->workspace()->firstOrFail();

        if ($request->validated('scheduled_at') !== null && ! $subscriptions->canPublish($workspace)) {
            if ($request->headers->has('X-Inertia')) {
                return redirect()->route('billing.index');
            }

            return response()->json([
                'message' => 'Subscribe to publish this post.',
                'billing_url' => route('billing.index'),
            ], 402);
        }

        $scheduledAt = $request->validated('scheduled_at');

        if ($scheduledAt !== null) {
            $post->scheduled_at = $scheduledAt;
            $post->status = PostStatus::Scheduled;
        } else {
            $post->scheduled_at = null;
            $post->status = PostStatus::Draft;
        }

        $post->save();

        if ($request->headers->has('X-Inertia')) {
            return back();
        }

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }
}
