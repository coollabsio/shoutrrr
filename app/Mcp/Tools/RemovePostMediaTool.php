<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\PostMedia;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('remove_post_media')]
#[Description('Remove an image from the workspace media library by id.')]
class RemovePostMediaTool extends WorkspaceTool
{
    public function handle(Request $request): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate(['media_id' => ['required', 'string']]);

        $media = PostMedia::query()->whereKey($validated['media_id'])->first();
        if ($media === null) {
            return Response::error('No media with that id exists in this workspace.');
        }

        $media->delete();

        return Response::text(json_encode(['deleted' => true, 'id' => $validated['media_id']], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['media_id' => $schema->string()->description('Id of the media to remove.')->required()];
    }
}
