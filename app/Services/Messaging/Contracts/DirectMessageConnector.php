<?php

declare(strict_types=1);

namespace App\Services\Messaging\Contracts;

use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Services\Messaging\Data\ConversationFetchResult;
use App\Services\Messaging\Data\MessageSendResult;
use Carbon\CarbonImmutable;

interface DirectMessageConnector
{
    /** @param array<string, mixed> $credentials */
    public function fetchConversations(ConnectedAccount $account, array $credentials, ?CarbonImmutable $since): ConversationFetchResult;

    /** @param array<string, mixed> $credentials */
    public function sendMessage(ConnectedAccount $account, Conversation $conversation, string $text, array $credentials): MessageSendResult;
}
