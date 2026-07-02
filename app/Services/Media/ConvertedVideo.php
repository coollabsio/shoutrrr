<?php

declare(strict_types=1);

namespace App\Services\Media;

final readonly class ConvertedVideo
{
    public function __construct(
        public string $disk,
        public string $path,
        public int $sizeBytes,
    ) {}
}
