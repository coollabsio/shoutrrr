<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Platform;
use App\Support\Concerns\PinsCurlResolution;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Downloads an MP4 from a user-supplied URL with the same SSRF protections as
 * SafeImageFetcher (scheme allow-list, private-range rejection, IP pinning, no
 * redirects), but capped at the video ceiling and validated by the ISO-BMFF
 * `ftyp` box rather than by image headers.
 */
class SafeVideoFetcher
{
    use PinsCurlResolution;

    /**
     * One message for refused connections and HTTP errors alike, so the
     * caller cannot use the fetcher to tell open ports from closed ones.
     */
    private const string DOWNLOAD_FAILED = 'Could not download the video.';

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @return array{bytes: string, mime: string}
     *
     * @throws RuntimeException if the URL is blocked or the response is not an MP4.
     */
    public function fetch(string $url): array
    {
        $response = $this->get($url, 20);

        $ceiling = $this->ceiling();

        // Check the advertised length BEFORE materialising the body. body()
        // buffers the whole response into a PHP string, so a clip larger than
        // memory_limit would raise a fatal allocation error — which is not a
        // RuntimeException, so the controllers' catch would miss it and the
        // caller would get a bare 500 instead of a clean message.
        $advertised = $response->header('Content-Length');

        if ($advertised !== '' && (int) $advertised > $ceiling) {
            throw new RuntimeException('Clip exceeds the maximum allowed video size.');
        }

        $bytes = $response->body();

        // Still check the real length: Content-Length is optional, and a
        // chunked response can omit or understate it.
        if (strlen($bytes) > $ceiling) {
            throw new RuntimeException('Clip exceeds the maximum allowed video size.');
        }

        // Same check PostVideoUploadController applies to direct-to-storage
        // uploads: an ISO-BMFF `ftyp` box at offset 4.
        if (substr($bytes, 4, 4) !== 'ftyp') {
            throw new RuntimeException('The downloaded clip is not a valid MP4 video.');
        }

        return ['bytes' => $bytes, 'mime' => 'video/mp4'];
    }

    /**
     * Stream an MP4 straight to $path instead of into memory, for videos that
     * can be far larger than memory_limit. Same SSRF guards and size ceiling.
     *
     * @throws RuntimeException if the URL is blocked or the file is not an MP4.
     */
    public function fetchToFile(string $url, string $path): void
    {
        $ceiling = $this->ceiling();
        $tooLarge = false;

        try {
            $this->get($url, 300, [
                'sink' => $path,
                // Abort mid-transfer once the advertised or received size passes the cap.
                'progress' => static function (int $total, int $received) use ($ceiling, &$tooLarge): void {
                    if ($total > $ceiling || $received > $ceiling) {
                        $tooLarge = true;

                        throw new RuntimeException('Video exceeds the maximum allowed video size.');
                    }
                },
            ]);
        } catch (RuntimeException $e) {
            // The abort reaches us re-wrapped as a connection error.
            throw $tooLarge ? new RuntimeException('Video exceeds the maximum allowed video size.') : $e;
        }

        if ((int) filesize($path) > $ceiling) {
            throw new RuntimeException('Video exceeds the maximum allowed video size.');
        }

        $head = (string) file_get_contents($path, length: 12);
        if (substr($head, 4, 4) !== 'ftyp') {
            throw new RuntimeException('The downloaded video is not a valid MP4.');
        }
    }

    private function ceiling(): int
    {
        return (int) config('media.max_video_bytes', Platform::maxVideoBytesCeiling());
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws RuntimeException
     */
    private function get(string $url, int $timeout, array $options = []): Response
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Video URL must use http or https.');
        }

        $rawHost = parse_url($url, PHP_URL_HOST);
        if (! is_string($rawHost) || $rawHost === '') {
            throw new RuntimeException('Video URL has no host.');
        }

        $host = strtolower(trim($rawHost, '[]'));
        $ips = PublicIp::resolve($host);

        try {
            $response = $this->http
                ->timeout($timeout)
                ->connectTimeout(5)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => $this->pinnedResolution($host, (string) $scheme, $url, $ips),
                    ...$options,
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            Log::info('Video download failed to connect.', ['url' => $url, 'error' => $e->getMessage()]);

            throw new RuntimeException(self::DOWNLOAD_FAILED);
        }

        if (! $response->successful()) {
            Log::info('Video download returned an error status.', ['url' => $url, 'status' => $response->status()]);

            throw new RuntimeException(self::DOWNLOAD_FAILED);
        }

        return $response;
    }
}
