<?php

declare(strict_types=1);

namespace App\Dto\Engagement;

use Carbon\CarbonImmutable;

final readonly class FetchedReply
{
    public function __construct(
        public string $remoteReplyId,
        public ?string $remoteCid,
        public ?string $parentRemoteId,
        public string $authorHandle,
        public ?string $authorName,
        public ?string $authorAvatarUrl,
        public string $text,
        public CarbonImmutable $remoteCreatedAt,
    ) {}
}
