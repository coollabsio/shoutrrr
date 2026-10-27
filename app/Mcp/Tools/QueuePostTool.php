<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\NextSlotResolver;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Schedule a post into the next open slot of the workspace posting schedule, or into a specific open slot from get_next_slot. The returned post.scheduled_at is the chosen slot.')]
class QueuePostTool extends WorkspaceTool
{
    public function handle(Request $request, NextSlotResolver $resolver, WorkspaceSubscriptionGate $subscriptions): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $post)) {
            return $denied;
        }

        $workspace = Workspace::query()->whereKey($workspaceId)->firstOrFail();

        if (! $subscriptions->canPublish($workspace)) {
            return Response::error('This workspace requires an active subscription before publishing.');
        }

        $slot = $resolver->pick($workspace, $validated['scheduled_at'] ?? null);

        if ($slot === null) {
            return Response::error(isset($validated['scheduled_at'])
                ? 'That time is not an open slot. Pick one from get_next_slot.'
                : 'No open posting slot available. Add posting-schedule slots first (see get_posting_schedule).');
        }

        $post->scheduled_at = $slot;
        $post->status = PostStatus::Scheduled;
        $post->save();

        return Response::text(json_encode(PostView::make($post->fresh(['targets.account', 'media'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post to queue.')->required(),
            'scheduled_at' => $schema->string()->description('Optional ISO-8601 open slot from get_next_slot. Omit to take the next open slot.'),
        ];
    }
}
