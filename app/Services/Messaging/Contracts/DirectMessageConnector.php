<?php

declare(strict_types=1);

namespace App\Services\Messaging\Contracts;

use App\Dto\Messaging\ConversationFetchResult;
use App\Dto\Messaging\MessageSendResult;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\PostMedia;
use Carbon\CarbonImmutable;

interface DirectMessageConnector
{
    /** @param array<string, mixed> $credentials */
    public function fetchConversations(ConnectedAccount $account, array $credentials, ?CarbonImmutable $since): ConversationFetchResult;

    /**
     * @param  array<string, mixed>  $credentials
     * @param  list<PostMedia>  $media  Attachments, capped by Platform::maxDirectMessageMedia().
     */
    public function sendMessage(ConnectedAccount $account, Conversation $conversation, string $text, array $credentials, array $media = []): MessageSendResult;
}
