<?php

declare(strict_types=1);

namespace App\Dto\Messaging;

use Carbon\CarbonImmutable;

final readonly class FetchedConversation
{
    /** @param list<FetchedMessage> $messages */
    public function __construct(
        public string $remoteConversationId,
        public ?string $counterpartHandle,
        public ?string $counterpartName,
        public ?string $counterpartAvatarUrl,
        public ?string $counterpartRemoteId,
        public ?CarbonImmutable $messagingWindowExpiresAt,
        public array $messages,
    ) {}
}
