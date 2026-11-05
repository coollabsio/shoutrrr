<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Dto\Gifs\GifItem;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Services\Gifs\KlipyClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use RuntimeException;

#[Name('search_gifs')]
#[Description('Search GIFs, stickers, or short clips (trending when query is empty). Pass a returned item to attach_gif to add it to the media library.')]
class SearchGifsTool extends WorkspaceTool
{
    public function handle(Request $request, KlipyClient $klipy): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        if ($denied = $this->authorize($request, 'create', Post::class)) {
            return $denied;
        }

        if (! $klipy->configured()) {
            return Response::error('GIF search is not enabled on this instance.');
        }

        $validated = $request->validate([
            'catalog' => ['nullable', 'string', Rule::in(KlipyClient::CATALOGS)],
            'query' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $catalog = $validated['catalog'] ?? 'gif';
        $query = trim((string) ($validated['query'] ?? ''));
        $page = $validated['page'] ?? 1;

        try {
            // Same key, TTL, and cached shape as the web GIF browser.
            $result = Cache::remember(KlipyClient::browseCacheKey($catalog, $query, $page), KlipyClient::BROWSE_CACHE_SECONDS, static function () use ($klipy, $catalog, $query, $page): array {
                $browsed = $klipy->browse($catalog, $query, $page);

                return [
                    'items' => array_map(static fn (GifItem $item): array => $item->toArray(), $browsed['items']),
                    'has_next' => $browsed['has_next'],
                ];
            });
        } catch (RuntimeException) {
            return Response::error('GIF search is unavailable right now.');
        }

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'catalog' => $schema->string()->enum(KlipyClient::CATALOGS)->description('gif (default), sticker, or clip (short MP4 with sound).'),
            'query' => $schema->string()->description('Search terms. Omit for trending.'),
            'page' => $schema->integer()->description('1-based page (max 50).'),
        ];
    }
}
