<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Publishing\PublishDispatcher;
use App\Support\PostView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublishController extends Controller
{
    public function store(Request $request, Post $post, PublishDispatcher $dispatcher): JsonResponse
    {
        abort_unless($request->user()->can('update', $post), 403);

        $post->forceFill(['status' => PostStatus::Publishing->value])->save();

        $dispatcher->dispatchForPost($post);

        return response()->json(['post' => PostView::make($post->fresh(['targets.account', 'media']))]);
    }
}
