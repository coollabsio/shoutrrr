<?php

declare(strict_types=1);

namespace App\Services\Messaging\Connectors;

use App\Enums\MessageDirection;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Services\Messaging\Connectors\Concerns\InteractsWithMetaGraph;
use App\Services\Messaging\Contracts\DirectMessageConnector;
use App\Services\Messaging\Data\ConversationFetchResult;
use App\Services\Messaging\Data\FetchedConversation;
use App\Services\Messaging\Data\FetchedMessage;
use App\Services\Messaging\Data\MessageSendResult;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Lists conversations and sends replies via the Facebook Page Messenger
 * (Graph API) Conversations endpoint, using the Page access token stored
 * on the connected account. Meta enforces a 24-hour reply window from the
 * counterpart's latest inbound message; sends outside that window are
 * declined locally without hitting the API.
 */
class FacebookDirectMessageConnector implements DirectMessageConnector
{
    use InteractsWithMetaGraph;
    use TracksUsage;

    private const string PLATFORM_PARAM = 'messenger';

    public function __construct(private readonly HttpFactory $http) {}

    /** @param array<string, mixed> $credentials */
    public function fetchConversations(ConnectedAccount $account, array $credentials, ?CarbonImmutable $since): ConversationFetchResult
    {
        $token = (string) ($credentials['access_token'] ?? '');
        $response = $this->http->acceptJson()->get($this->metaGraphBase()."/{$account->remote_account_id}/conversations", [
            'platform' => self::PLATFORM_PARAM,
            'fields' => 'participants,updated_time,messages{id,from,message,created_time}',
            'limit' => 50,
            'access_token' => $token,
        ]);

        $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_FETCH, $account, $response);

        if ($response->failed() || $response->json('error')) {
            return $this->mapMetaFetchFailure($response);
        }

        $ourId = (string) $account->remote_account_id;
        $conversations = [];

        foreach ($response->json('data', []) as $convo) {
            $conversations[] = $this->mapConversation($convo, $ourId);
        }

        return ConversationFetchResult::ok($conversations, data_get($response->json(), 'paging.cursors.after'));
    }

    /** @param array<string, mixed> $convo */
    private function mapConversation(array $convo, string $ourId): FetchedConversation
    {
        /** @var array<int, array<string, mixed>> $participants */
        $participants = $convo['participants']['data'] ?? [];
        $counterpart = collect($participants)
            ->first(fn (array $p): bool => (string) ($p['id'] ?? '') !== $ourId);

        $messages = [];
        $latestInbound = null;

        foreach ($convo['messages']['data'] ?? [] as $m) {
            $fromId = (string) ($m['from']['id'] ?? '');
            $inbound = $fromId !== $ourId;
            $createdAt = CarbonImmutable::parse($m['created_time']);

            if ($inbound && ($latestInbound === null || $createdAt->gt($latestInbound))) {
                $latestInbound = $createdAt;
            }

            $messages[] = new FetchedMessage(
                remoteMessageId: (string) $m['id'],
                direction: $inbound ? MessageDirection::Inbound : MessageDirection::Outbound,
                authorRemoteId: $fromId,
                text: $m['message'] ?? null,
                attachments: [],
                remoteCreatedAt: $createdAt,
            );
        }

        return new FetchedConversation(
            remoteConversationId: (string) $convo['id'],
            counterpartHandle: null,
            counterpartName: $counterpart['name'] ?? null,
            counterpartAvatarUrl: null,
            counterpartRemoteId: $counterpart['id'] ?? null,
            messagingWindowExpiresAt: $this->metaWindowFrom($latestInbound),
            messages: $messages,
        );
    }

    /** @param array<string, mixed> $credentials */
    public function sendMessage(ConnectedAccount $account, Conversation $conversation, string $text, array $credentials): MessageSendResult
    {
        if (! $conversation->canReplyNow()) {
            return MessageSendResult::unsupported('The 24-hour messaging window for this conversation has closed.');
        }

        $token = (string) ($credentials['access_token'] ?? '');
        $response = $this->http->acceptJson()->post($this->metaGraphBase()."/{$account->remote_account_id}/messages", [
            'recipient' => ['id' => $conversation->counterpart_remote_id],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $text],
            'access_token' => $token,
        ]);

        $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_SEND, $account, $response);

        if ($response->failed() || $response->json('error')) {
            return $this->mapMetaSendFailure($response);
        }

        return MessageSendResult::ok((string) $response->json('message_id'));
    }
}
