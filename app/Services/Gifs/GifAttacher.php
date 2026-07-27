<?php

declare(strict_types=1);

namespace App\Services\Gifs;

use App\Enums\Platform;
use App\Models\PostMedia;
use App\Services\Posts\MediaStorageService;
use App\Support\FileStorage;
use App\Support\SafeVideoFetcher;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

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

    public function __construct(
        private readonly MediaStorageService $media,
        private readonly SafeVideoFetcher $video,
    ) {}

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
        ?int $durationSeconds = null,
    ): PostMedia {
        $existing = is_array($existingMedia) ? $existingMedia : iterator_to_array($existingMedia);

        // Variant selection happens first so the guard can see the chosen
        // variant's mime — the mixing rule is about mime (a sticker can
        // legitimately be image/gif), not about the catalog it came from.
        // pickVariant() still enforces the host allow-list on every variant
        // before anything else happens, and before any fetch is attempted.
        // Clips are checked against the video ceiling rather than the image
        // one, since they route through SafeVideoFetcher instead of storeFromUrl.
        $maxBytes = $catalog === 'clip' ? Platform::maxVideoBytesCeiling() : self::MAX_IMAGE_BYTES;
        $variant = $this->pickVariant($variants, $maxBytes);

        $this->guardMediaRules($catalog, $variant['mime'], $existing);

        if ($catalog === 'clip') {
            return $this->storeClip($workspaceId, $variant, $title, $durationSeconds);
        }

        return $this->media->storeFromUrl($workspaceId, $variant['url'], $title !== '' ? $title : null);
    }

    /**
     * Largest variant whose reported size fits the cap. Variants with no reported
     * size are tried too — the fetcher's own cap is the real gate.
     *
     * @param  list<array<string, mixed>>  $variants
     * @return array{url: string, mime: string, width: int, height: int}
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
            $width = isset($variant['width']) ? (int) $variant['width'] : 0;
            $height = isset($variant['height']) ? (int) $variant['height'] : 0;

            if ($bytes === null || $bytes <= $maxBytes) {
                $usable[] = ['url' => $url, 'mime' => $mime, 'bytes' => $bytes ?? 0, 'width' => $width, 'height' => $height];
            }
        }

        if ($usable === []) {
            throw new RuntimeException('That GIF is too large to attach.');
        }

        usort($usable, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return [
            'url' => $usable[0]['url'],
            'mime' => $usable[0]['mime'],
            'width' => $usable[0]['width'],
            'height' => $usable[0]['height'],
        ];
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

    /**
     * Store a clip as a video PostMedia row, mirroring PostVideoUploadController's
     * finalize step. Dimensions come from the chosen variant (already picked by
     * pickVariant(), including the host allow-list check); duration comes from
     * Klipy or ffprobe, and its absence is fatal — a video row with no duration
     * silently bypasses every platform's duration check at publish time.
     *
     * @param  array{url: string, mime: string, width: int, height: int}  $variant
     */
    private function storeClip(
        string $workspaceId,
        array $variant,
        string $title,
        ?int $durationSeconds,
    ): PostMedia {
        $clip = $this->video->fetch($variant['url']);

        $temp = tempnam(sys_get_temp_dir(), 'klipy');

        if ($temp === false) {
            throw new RuntimeException('Could not attach that clip.');
        }

        file_put_contents($temp, $clip['bytes']);

        try {
            $duration = $durationSeconds ?? $this->probeDuration($temp);

            if ($duration === null || $duration < 1) {
                throw new RuntimeException('That clip has no readable duration, so it cannot be attached.');
            }

            $disk = FileStorage::diskName();
            $path = 'media/'.$workspaceId.'/'.Str::uuid()->toString().'.mp4';
            FileStorage::disk($disk)->put($path, $clip['bytes']);

            return PostMedia::create([
                'workspace_id' => $workspaceId,
                'post_id' => null,
                'disk' => $disk,
                'path' => $path,
                'kind' => 'video',
                'mime' => 'video/mp4',
                'size_bytes' => strlen($clip['bytes']),
                'width' => $variant['width'],
                'height' => $variant['height'],
                'duration_seconds' => $duration,
                'alt_text' => $title !== '' ? $title : null,
                'position' => 0,
            ]);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Read a duration with ffprobe when it is installed. Returns null when the
     * binary is missing or the probe fails — ffmpeg is an optional runtime
     * dependency here (GifToMp4Converter treats it the same way).
     */
    private function probeDuration(string $path): ?int
    {
        $ffprobe = (new ExecutableFinder)->find('ffprobe');

        if ($ffprobe === null) {
            return null;
        }

        $process = new Process([
            $ffprobe, '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $seconds = (float) trim($process->getOutput());

        return $seconds > 0 ? (int) ceil($seconds) : null;
    }
}
