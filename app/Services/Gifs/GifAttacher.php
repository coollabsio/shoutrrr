<?php

declare(strict_types=1);

namespace App\Services\Gifs;

use App\Models\PostMedia;
use App\Services\Posts\MediaStorageService;
use RuntimeException;

/**
 * Turns a browsed Klipy item into a stored PostMedia row: picks the largest
 * variant that fits the relevant size cap, enforces the same media-mixing rules
 * the composer applies client-side, and routes GIFs/stickers to the image
 * pipeline and clips to the video one.
 */
class GifAttacher
{
    /**
     * Only these hosts may be downloaded from. The client sends a variant URL,
     * so without this an attacker could point the attach endpoint anywhere the
     * SSRF guard still permits (any public host).
     */
    public const array ALLOWED_HOST_SUFFIXES = ['klipy.com', 'klipy.co'];

    /** Matches SafeImageFetcher's own cap; checked up front to avoid a doomed download. */
    private const int MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly MediaStorageService $media) {}

    /**
     * @param  list<array<string, mixed>>  $variants  Client-sent variants, any order.
     * @param  iterable<PostMedia>  $existingMedia
     *
     * @throws RuntimeException with a message safe to show the user.
     */
    public function attach(
        string $workspaceId,
        string $catalog,
        string $title,
        array $variants,
        iterable $existingMedia,
    ): PostMedia {
        $existing = is_array($existingMedia) ? $existingMedia : iterator_to_array($existingMedia);

        // Variant selection happens first so the guard can see the chosen
        // variant's mime — the mixing rule is about mime (a sticker can
        // legitimately be image/gif), not about the catalog it came from.
        // pickVariant() still enforces the host allow-list on every variant
        // before anything else happens, and before any fetch is attempted.
        $variant = $this->pickVariant($variants, self::MAX_IMAGE_BYTES);

        $this->guardMediaRules($catalog, $variant['mime'], $existing);

        return $this->media->storeFromUrl($workspaceId, $variant['url'], $title !== '' ? $title : null);
    }

    /**
     * Largest variant whose reported size fits the cap. Variants with no reported
     * size are tried too — the fetcher's own cap is the real gate.
     *
     * @param  list<array<string, mixed>>  $variants
     * @return array{url: string, mime: string}
     */
    private function pickVariant(array $variants, int $maxBytes): array
    {
        $usable = [];

        foreach ($variants as $variant) {
            $url = is_string($variant['url'] ?? null) ? $variant['url'] : null;

            if ($url === null || ! $this->isAllowedHost($url)) {
                throw new RuntimeException('That is not a supported GIF source.');
            }

            $bytes = isset($variant['bytes']) ? (int) $variant['bytes'] : null;
            $mime = is_string($variant['mime'] ?? null) ? $variant['mime'] : 'image/gif';

            if ($bytes === null || $bytes <= $maxBytes) {
                $usable[] = ['url' => $url, 'mime' => $mime, 'bytes' => $bytes ?? 0];
            }
        }

        if ($usable === []) {
            throw new RuntimeException('That GIF is too large to attach.');
        }

        usort($usable, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return ['url' => $usable[0]['url'], 'mime' => $usable[0]['mime']];
    }

    private function isAllowedHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Server-side mirror of the composer's client-side rules
     * (resources/js/lib/compose/media-rules.ts, wouldViolateBlueskyGif). The
     * rule is about mime, not catalog — a sticker can legitimately resolve to
     * an image/gif variant (KlipyClient::normalizeItem() builds stickers from
     * both 'gif' and 'webp' formats), so an animated GIF must be the only
     * attachment regardless of which catalog it was browsed from. Applied
     * uniformly since the server doesn't know the post's target platforms at
     * attach time. A clip is a video, so it cannot join images either.
     *
     * @param  list<PostMedia>  $existing
     */
    private function guardMediaRules(string $catalog, string $mime, array $existing): void
    {
        if ($catalog === 'clip') {
            if ($existing !== []) {
                throw new RuntimeException('A clip has to be the only attachment on a post.');
            }

            return;
        }

        if ($mime === 'image/gif' && $existing !== []) {
            throw new RuntimeException('An animated GIF has to go on its own.');
        }

        foreach ($existing as $media) {
            if ($media->mime === 'image/gif') {
                throw new RuntimeException('An animated GIF has to go on its own.');
            }

            if ($media->kind === 'video') {
                throw new RuntimeException('You cannot mix images and video on one post.');
            }
        }
    }
}
