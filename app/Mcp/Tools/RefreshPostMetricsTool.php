<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostTargetStatus;
use App\Jobs\CapturePostTargetMetrics;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\PostTarget;
use App\Support\InstanceSettings;
use App\Support\MetricsPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('refresh_post_metrics')]
#[Description('Fetch fresh likes, comments, reposts, and impressions from each platform for a published post, then return its metrics: totals, per-target counts and status, and history. Limited to 10 refreshes per minute.')]
class RefreshPostMetricsTool extends WorkspaceTool
{
    /** Mirrors the Classic route's throttle:10,1, since dispatchSync bypasses the job's own throttle. */
    private const int MAX_ATTEMPTS_PER_MINUTE = 10;

    public function handle(Request $request, InstanceSettings $settings): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if (! $settings->metricsEnabled()) {
            return Response::error('Post metrics are disabled on this instance.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();

        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'view', $post)) {
            return $denied;
        }

        $limiterKey = 'mcp:refresh-post-metrics:'.$request->user()?->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_ATTEMPTS_PER_MINUTE)) {
            return Response::error('Too many metric refreshes. Try again in '.RateLimiter::availableIn($limiterKey).' seconds.');
        }

        RateLimiter::hit($limiterKey, 60);

        $post->targets()
            ->where('status', PostTargetStatus::Published->value)
            ->whereNotNull('remote_id')
            ->get()
            ->each(fn (PostTarget $target) => CapturePostTargetMetrics::dispatchSync($target));

        return Response::text(json_encode([
            'post_id' => $post->id,
            ...MetricsPresenter::forPost($post->fresh() ?? $post),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the published post to refresh.')->required(),
        ];
    }
}
