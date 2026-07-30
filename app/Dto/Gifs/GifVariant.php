<?php

declare(strict_types=1);

namespace App\Dto\Gifs;

/**
 * One downloadable representation of a Klipy item. `bytes` is null when Klipy
 * does not report a size — the caller then relies on the fetcher's own cap.
 */
readonly class GifVariant
{
    public function __construct(
        public string $url,
        public string $mime,
        public int $width,
        public int $height,
        public ?int $bytes,
    ) {}

    /**
     * @return array{url: string, mime: string, width: int, height: int, bytes: int|null}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'mime' => $this->mime,
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
        ];
    }
}
