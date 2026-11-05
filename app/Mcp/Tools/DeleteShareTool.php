<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\PostShare;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_share')]
#[Description('Revoke a share link for a post in the bound workspace.')]
class DeleteShareTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'share_id' => ['required', 'string'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        $share = PostShare::query()->whereKey($validated['share_id'])->first();
        if ($share === null || $share->post_id !== $post->id) {
            return Response::error('No share with that id exists for this post.');
        }

        $share->forceFill(['revoked_at' => now()])->save();

        return Response::text(json_encode(['revoked' => true], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post.')->required(),
            'share_id' => $schema->string()->description('Id of the share link to revoke.')->required(),
        ];
    }
}
