<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Publishing\PublishDispatcher;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublishController extends Controller
{
    public function store(Request $request, Post $post, PublishDispatcher $dispatcher, WorkspaceSubscriptionGate $subscriptions): JsonResponse
    {
        abort_unless($request->user()->can('update', $post), 403);

        $workspace = $post->workspace()->firstOrFail();

        if (! $subscriptions->canPublish($workspace)) {
            return response()->json([
                'message' => 'Subscribe to publish this post.',
                'billing_url' => route('billing.index'),
            ], 402);
        }

        $post->forceFill(['status' => PostStatus::Publishing->value])->save();

        $dispatcher->dispatchForPost($post);

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }
}
