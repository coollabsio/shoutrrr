<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Enums\PostTargetStatus;
use App\Http\Controllers\Controller;
use App\Jobs\CapturePostTargetMetrics;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\MetricsPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostMetricsRefreshController extends Controller
{
    public function store(Request $request, Post $post): JsonResponse
    {
        $request->user()->can('view', $post) ?: abort(403);

        $post->targets()
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('remote_id')
            ->get()
            ->each(fn (PostTarget $target) => CapturePostTargetMetrics::dispatchSync($target));

        return response()->json(MetricsPresenter::forPost($post->fresh() ?? $post));
    }
}
