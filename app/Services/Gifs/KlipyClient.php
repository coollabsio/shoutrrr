<?php

declare(strict_types=1);

namespace App\Services\Gifs;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;
use RuntimeException;

/**
 * The only class that knows Klipy's URL shape. The API key is a path segment, so
 * it can never be exposed to the browser and must be redacted from any message
 * that escapes this class.
 */
class KlipyClient
{
    public const array CATALOGS = ['gif', 'sticker', 'clip'];

    private const string BASE = 'https://api.klipy.com/api/v1';

    /** Klipy's own path segment per catalog. */
    private const array PATHS = ['gif' => 'gifs', 'sticker' => 'stickers', 'clip' => 'clips'];

    /** Mime per file-format key Klipy returns. */
    private const array MIMES = ['gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4'];

    public function __construct(private readonly HttpFactory $http) {}

    public function configured(): bool
    {
        return is_string(config('services.klipy.key')) && config('services.klipy.key') !== '';
    }

    /**
     * Trending when `$query` is null or empty, search otherwise.
     *
     * @return array{items: list<GifItem>, has_next: bool}
     */
    public function browse(string $catalog, ?string $query, int $page): array
    {
        $path = $this->pathFor($catalog);
        $query = $query === null ? '' : trim($query);

        $params = [
            'page' => (string) $page,
            'per_page' => 24,
            'rating' => (string) config('services.klipy.rating', 'pg-13'),
        ];

        if ($query !== '') {
            $params['q'] = $query;
        }

        return $this->get($catalog, $path.'/'.($query === '' ? 'trending' : 'search'), $params);
    }

    /**
     * @return array{items: list<GifItem>, has_next: bool}
     */
    public function recent(string $catalog, string $customerId, int $page): array
    {
        return $this->get($catalog, $this->pathFor($catalog).'/recent/'.urlencode($customerId), [
            'page' => $page,
            'per_page' => 24,
        ]);
    }

    /**
     * Report a share back to Klipy. Best-effort: never throws into the caller's
     * request, since the user's attach has already succeeded by this point. A
     * connection-level failure (DNS, refused, timeout) is swallowed rather than
     * rethrown, since Guzzle embeds the full request URI — API key included — in
     * that exception's message.
     */
    public function share(string $catalog, string $slug, string $customerId): void
    {
        try {
            $this->http->timeout(5)->post($this->url($this->pathFor($catalog).'/share'), [
                'slug' => $slug,
                'customer_id' => $customerId,
            ]);
        } catch (ConnectionException) {
            // Best-effort: nothing to do, nothing to report to the caller.
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{items: list<GifItem>, has_next: bool}
     */
    private function get(string $catalog, string $path, array $params): array
    {
        try {
            $response = $this->http->timeout(10)->connectTimeout(5)->get($this->url($path), $params);
        } catch (ConnectionException) {
            // Deliberately excludes the original exception/message — Guzzle embeds
            // the full request URI, which contains the API key, in its message.
            throw new RuntimeException('Klipy request failed (connection error).');
        }

        if (! $response->successful()) {
            // Deliberately excludes the URL — it contains the API key.
            throw new RuntimeException('Klipy request failed (HTTP '.$response->status().').');
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new RuntimeException('Klipy returned an unexpected payload.');
        }

        $items = [];

        foreach ($data['data'] ?? [] as $raw) {
            $item = $this->normalizeItem($catalog, is_array($raw) ? $raw : []);

            if ($item instanceof GifItem) {
                $items[] = $item;
            }
        }

        return ['items' => $items, 'has_next' => (bool) ($data['has_next'] ?? false)];
    }

    /**
     * Flatten Klipy's `file[size][format]` map into an ordered variant list.
     * Returns null for an item with no usable variant rather than failing the
     * whole page.
     *
     * @param  array<string, mixed>  $raw
     */
    private function normalizeItem(string $catalog, array $raw): ?GifItem
    {
        $wanted = $catalog === 'clip' ? ['mp4'] : ['gif', 'webp'];
        $variants = [];

        foreach ((array) ($raw['file'] ?? []) as $formats) {
            foreach ((array) $formats as $format => $file) {
                if (! in_array($format, $wanted, true) || ! is_array($file) || ! isset($file['url'])) {
                    continue;
                }

                $variants[] = new GifVariant(
                    url: (string) $file['url'],
                    mime: self::MIMES[$format],
                    width: (int) ($file['width'] ?? 0),
                    height: (int) ($file['height'] ?? 0),
                    bytes: isset($file['size']) ? (int) $file['size'] : null,
                );
            }
        }

        if ($variants === []) {
            return null;
        }

        // Largest first: the attacher walks down until one fits its cap, and the
        // smallest doubles as the grid preview.
        usort($variants, fn (GifVariant $a, GifVariant $b): int => ($b->bytes ?? PHP_INT_MAX) <=> ($a->bytes ?? PHP_INT_MAX));

        return new GifItem(
            id: (string) ($raw['id'] ?? $raw['slug'] ?? ''),
            slug: (string) ($raw['slug'] ?? ''),
            catalog: $catalog,
            title: (string) ($raw['title'] ?? ''),
            preview: $variants[count($variants) - 1],
            variants: $variants,
        );
    }

    private function pathFor(string $catalog): string
    {
        if (! array_key_exists($catalog, self::PATHS)) {
            throw new InvalidArgumentException('Unknown GIF catalog: '.$catalog);
        }

        return self::PATHS[$catalog];
    }

    private function url(string $path): string
    {
        return self::BASE.'/'.(string) config('services.klipy.key').'/'.$path;
    }
}
