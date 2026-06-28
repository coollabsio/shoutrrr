<?php

declare(strict_types=1);

namespace App\Services\Media;

final class CompressionResult
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly bool $wasCompressed,
    ) {}

    public static function untouched(string $bytes, string $mime): self
    {
        return new self($bytes, $mime, false);
    }

    public static function compressed(string $bytes): self
    {
        return new self($bytes, 'image/jpeg', true);
    }
}
