<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_media_alt')]
#[Description('Set or clear the accessibility alt text of an image or video in the workspace media library. Media on a published post can no longer be changed.')]
class UpdateMediaAltTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'media_id' => ['required', 'string'],
            'alt_text' => ['present', 'nullable', 'string', 'max:1000'],
        ]);

        $media = PostMedia::query()->whereKey($validated['media_id'])->first();
        if ($media === null) {
            return Response::error('No media with that id exists in this workspace.');
        }

        // Unattached library media is authored like a new post; attached media
        // follows the Classic rule: editable posts only, with update rights.
        $post = $media->post;
        if ($denied = $this->authorize($request, $post instanceof Post ? 'update' : 'create', $post ?? Post::class)) {
            return $denied;
        }

        if ($post instanceof Post && ! $post->status->isEditable()) {
            return Response::error('This post can no longer be edited.');
        }

        $media->update(['alt_text' => $validated['alt_text']]);

        return Response::text(json_encode([
            'id' => $media->id,
            'alt_text' => $media->alt_text,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'media_id' => $schema->string()->description('Id of the media to update.')->required(),
            'alt_text' => $schema->string()->nullable()->description('New alt text (max 1000 characters). Null clears it.')->required(),
        ];
    }
}
