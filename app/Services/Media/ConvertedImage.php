<?php

declare(strict_types=1);

namespace App\Services\Media;

final readonly class ConvertedImage
{
    public function __construct(
        public string $disk,
        public string $path,
    ) {}
}
