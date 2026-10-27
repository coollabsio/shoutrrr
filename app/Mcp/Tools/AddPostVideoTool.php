<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Posts\MediaStorageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use RuntimeException;

#[Name('add_post_video')]
#[Description('Attach an MP4 video to the workspace media library by downloading it from a public URL (capped at the largest size any platform accepts). Returns the media id to use in create_post/update_post media_ids. A post takes one video, or images, not both.')]
class AddPostVideoTool extends WorkspaceTool
{
    public function handle(Request $request, MediaStorageService $media): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->authorize($request, 'create', Post::class)) {
            return $denied;
        }

        $validated = $request->validate([
            'url' => ['required', 'url'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $stored = $media->storeVideoFromUrl($workspaceId, $validated['url'], $validated['alt_text'] ?? null);
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'id' => $stored->id,
            'mime' => $stored->mime,
            'kind' => $stored->kind,
            'width' => $stored->width,
            'height' => $stored->height,
            'duration_seconds' => $stored->duration_seconds,
            'alt_text' => $stored->alt_text,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('Public https URL of the MP4 file.')->required(),
            'alt_text' => $schema->string()->description('Accessibility description.'),
        ];
    }
}
