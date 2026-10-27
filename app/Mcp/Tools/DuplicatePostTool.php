<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Posts\PostDuplicator;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('duplicate_post')]
#[Description('Copy a published, partial, failed, or missed post into a new draft, including its text, media, destination, and per-account settings. Returns the new draft.')]
class DuplicatePostTool extends WorkspaceTool
{
    public function handle(Request $request, PostDuplicator $duplicator): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->authorize($request, 'create', Post::class)) {
            return $denied;
        }

        $validated = $request->validate(['post_id' => ['required', 'string']]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if (! $post->status->isDuplicable()) {
            return Response::error('Only published, partial, failed, or missed posts can be copied. Edit a draft or scheduled post with update_post instead.');
        }

        $draft = $duplicator->duplicate($post);

        return Response::text(json_encode(PostView::make($draft->fresh(['targets.account', 'media'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['post_id' => $schema->string()->description('Id of the post to copy.')->required()];
    }
}
