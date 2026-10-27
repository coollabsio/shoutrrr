<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Http\Controllers\Gifs\GifBrowserController;
use App\Http\Requests\Gifs\AttachGifRequest;
use App\Jobs\TriggerKlipyShare;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\Gifs\GifAttacher;
use App\Services\Gifs\KlipyClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use RuntimeException;

#[Name('attach_gif')]
#[Description('Add a GIF, sticker, or clip from search_gifs to the workspace media library. Pass the item\'s catalog, slug, title, and variants unchanged. Returns the media id to use in create_post/update_post media_ids. A GIF must be the only media in its post segment.')]
class AttachGifTool extends WorkspaceTool
{
    public function handle(Request $request, GifAttacher $attacher, KlipyClient $klipy): Response
    {
        $workspaceId = $this->bindWorkspace($request);
        if ($workspaceId === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->authorize($request, 'create', Post::class)) {
            return $denied;
        }

        if (! $klipy->configured()) {
            return Response::error('GIFs are not enabled on this instance.');
        }

        $validated = $request->validate((new AttachGifRequest)->rules());

        $existing = PostMedia::query()->whereIn('id', $validated['media_ids'] ?? [])->get();

        try {
            $media = $attacher->attach(
                $workspaceId,
                $validated['catalog'],
                (string) ($validated['title'] ?? ''),
                $validated['variants'],
                $existing,
                isset($validated['duration_seconds']) ? (int) $validated['duration_seconds'] : null,
            );
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        /** @var User $user */
        $user = $request->user();
        TriggerKlipyShare::maybeDispatch($validated['catalog'], $validated['slug'], GifBrowserController::customerId($user));

        return Response::text(json_encode([
            'id' => $media->id,
            'mime' => $media->mime,
            'kind' => $media->kind,
            'width' => $media->width,
            'height' => $media->height,
            'duration_seconds' => $media->duration_seconds,
            'alt_text' => $media->alt_text,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'catalog' => $schema->string()->enum(KlipyClient::CATALOGS)->required(),
            'slug' => $schema->string()->description('The item slug from search_gifs.')->required(),
            'title' => $schema->string()->description('The item title; becomes the alt text.'),
            'variants' => $schema->array()->items($schema->object([
                'url' => $schema->string()->required(),
                'mime' => $schema->string()->enum(['image/gif', 'image/webp', 'video/mp4'])->required(),
                'width' => $schema->integer()->required(),
                'height' => $schema->integer()->required(),
                'bytes' => $schema->integer(),
            ]))->description('The item variants from search_gifs, unchanged. The largest that fits the size cap is used.')->required(),
            'duration_seconds' => $schema->integer()->description('Clip length in seconds (clips only).'),
            'media_ids' => $schema->array()->items($schema->string())
                ->description('Media already in the same post segment, so the one-GIF-per-segment and no-mixing rules can be checked.'),
        ];
    }
}
