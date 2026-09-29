<?php

declare(strict_types=1);

namespace App\Dto\NativeRead;

use Carbon\CarbonImmutable;

final readonly class NativeReadCursor
{
    public function __construct(
        public CarbonImmutable $watermark,
        public ?string $lastSeenRemoteId,
    ) {}
}
