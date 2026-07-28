<?php

declare(strict_types=1);

namespace App\Services\Messaging\Data;

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
        public ?string $cursor = null,
    ) {}
}
