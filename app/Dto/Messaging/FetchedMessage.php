<?php

declare(strict_types=1);

namespace App\Dto\Messaging;

use App\Enums\MessageDirection;
use Carbon\CarbonImmutable;

final readonly class FetchedMessage
{
    /** @param array<int, mixed> $attachments */
    public function __construct(
        public string $remoteMessageId,
        public MessageDirection $direction,
        public ?string $authorRemoteId,
        public ?string $text,
        public array $attachments,
        public CarbonImmutable $remoteCreatedAt,
    ) {}
}
