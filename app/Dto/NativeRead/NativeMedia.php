<?php

declare(strict_types=1);

namespace App\Dto\NativeRead;

final readonly class NativeMedia
{
    public function __construct(
        public string $url,
        public string $kind,
        public ?string $mime = null,
    ) {}
}
