<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\DeletePostTarget;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete a post. A draft is removed permanently; a published post is also deleted from the connected accounts where possible. Irreversible. Requires confirm=true.')]
class DeletePostTool extends WorkspaceTool
{
    public function handle(Request $request): Response
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

        if ($denied = $this->authorize($request, 'delete', $post)) {
            return $denied;
        }

        $hadBeenPublished = in_array($post->status, [PostStatus::Published, PostStatus::Partial, PostStatus::Failed], true);
        $consequence = $hadBeenPublished
            ? 'This will delete the post from its connected accounts where possible.'
            : 'This will permanently delete the draft.';

        if ($unconfirmed = $this->requireConfirmation($request, $consequence)) {
            return $unconfirmed;
        }

        $post->loadMissing('targets');

        if (! $hadBeenPublished) {
            $post->delete();

            return Response::text(json_encode(['deleted' => true, 'remote' => false], JSON_THROW_ON_ERROR));
        }

        $targetsToDelete = $post->targets
            ->filter(fn (PostTarget $t): bool => $t->remote_id !== null || ($t->remote_ids ?? []) !== [])
            ->values();
        $targetIdsToDelete = $targetsToDelete->modelKeys();

        $post->targets
            ->reject(fn (PostTarget $t): bool => in_array($t->getKey(), $targetIdsToDelete, true))
            ->each(fn (PostTarget $t) => $t->forceFill(['status' => PostTargetStatus::Deleted->value])->save());

        $targetsToDelete->each(fn (PostTarget $t) => DeletePostTarget::dispatch($t));

        $post->forceFill(['status' => PostStatus::Deleted->value, 'deleted_at' => now()])->save();

        if ($targetsToDelete->isEmpty()) {
            return Response::text(json_encode(['deleted' => true, 'remote' => false], JSON_THROW_ON_ERROR));
        }

        return Response::text(json_encode(['deleted' => true, 'remote' => true, 'message' => 'Remote deletion queued for published targets.'], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post to delete.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to delete.'),
        ];
    }
}
