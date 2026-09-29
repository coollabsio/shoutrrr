<?php

declare(strict_types=1);

namespace App\Dto\NativeRead;

use Carbon\CarbonImmutable;

final readonly class NativePost
{
    /**
     * @param  list<NativeMedia>  $media
     */
    public function __construct(
        public string $remoteId,
        public string $text,
        public CarbonImmutable $createdAt,
        public array $media,
        public bool $isReply,
        public bool $isRepost,
    ) {}
}
