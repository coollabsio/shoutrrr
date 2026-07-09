<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Publishing\PublishDispatcher;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Publish a post to its connected accounts immediately. Irreversible and outward-facing. Requires confirm=true. Publishing is asynchronous — poll get_post for per-target results.')]
class PublishPostTool extends WorkspaceTool
{
    public function handle(Request $request, PublishDispatcher $dispatcher, WorkspaceSubscriptionGate $subscriptions): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'confirm' => ['boolean'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $post)) {
            return $denied;
        }

        if ($unconfirmed = $this->requireConfirmation($request, 'This will publicly publish the post to its connected accounts now.')) {
            return $unconfirmed;
        }

        if (! $subscriptions->canPublish($post->workspace()->firstOrFail())) {
            return Response::error('This workspace requires an active subscription before publishing.');
        }

        $post->forceFill(['status' => PostStatus::Publishing->value])->save();
        $dispatcher->dispatchForPost($post);

        return Response::text(json_encode([
            'status' => 'queued',
            'message' => 'Publishing started. Poll get_post for per-target status.',
            'post' => PostView::make($post->fresh(['targets.account', 'media'])),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post to publish.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to actually publish.'),
        ];
    }
}
