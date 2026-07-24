<?php

declare(strict_types=1);

namespace App\Services\Messaging\Connectors;

use App\Enums\MessageDirection;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Services\Engagement\RetryAfter;
use App\Services\Messaging\Contracts\DirectMessageConnector;
use App\Services\Messaging\Data\ConversationFetchResult;
use App\Services\Messaging\Data\FetchedConversation;
use App\Services\Messaging\Data\FetchedMessage;
use App\Services\Messaging\Data\MessageSendResult;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

class BlueskyDirectMessageConnector implements DirectMessageConnector
{
    use TracksUsage;

    private const string PROXY = 'did:web:api.bsky.chat#bsky_chat';

    private const string DEFAULT_PDS = 'https://bsky.social';

    public function __construct(private readonly HttpFactory $http) {}

    /** @param array<string, mixed> $credentials */
    public function fetchConversations(ConnectedAccount $account, array $credentials, ?CarbonImmutable $since): ConversationFetchResult
    {
        $session = $credentials['session'] ?? [];
        if (! isset($session['accessJwt']) || isset($session['dpop_private_jwk'])) {
            return ConversationFetchResult::unsupported('Bluesky DMs require an app-password session with DM scope.');
        }

        $list = $this->client($session)->get('/xrpc/chat.bsky.convo.listConvos', ['limit' => 100]);
        $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_FETCH, $account, $list);

        if ($list->failed()) {
            return $this->mapFetchFailure($list);
        }

        $ourDid = (string) $account->remote_account_id;
        $conversations = [];

        foreach ($list->json('convos', []) as $convo) {
            $convoId = (string) $convo['id'];
            $counterpart = collect($convo['members'] ?? [])->first(fn ($m) => ($m['did'] ?? null) !== $ourDid);

            $msgResponse = $this->client($session)->get('/xrpc/chat.bsky.convo.getMessages', ['convoId' => $convoId, 'limit' => 50]);
            $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_FETCH, $account, $msgResponse);
            if ($msgResponse->failed()) {
                return $this->mapFetchFailure($msgResponse);
            }

            $messages = [];
            foreach ($msgResponse->json('messages', []) as $m) {
                if (! isset($m['id'])) {
                    continue; // skip deleted-message tombstones
                }
                $senderDid = (string) ($m['sender']['did'] ?? '');
                $messages[] = new FetchedMessage(
                    remoteMessageId: (string) $m['id'],
                    direction: $senderDid === $ourDid ? MessageDirection::Outbound : MessageDirection::Inbound,
                    authorRemoteId: $senderDid,
                    text: $m['text'] ?? null,
                    attachments: [],
                    remoteCreatedAt: CarbonImmutable::parse($m['sentAt']),
                );
            }

            $handle = $counterpart['handle'] ?? null;
            $conversations[] = new FetchedConversation(
                remoteConversationId: $convoId,
                counterpartHandle: $handle ? '@'.$handle : null,
                counterpartName: $counterpart['displayName'] ?? null,
                counterpartAvatarUrl: $counterpart['avatar'] ?? null,
                counterpartRemoteId: $counterpart['did'] ?? null,
                messagingWindowExpiresAt: null,
                messages: $messages,
            );
        }

        return ConversationFetchResult::ok($conversations, $list->json('cursor'));
    }

    /** @param array<string, mixed> $credentials */
    public function sendMessage(ConnectedAccount $account, Conversation $conversation, string $text, array $credentials): MessageSendResult
    {
        $session = $credentials['session'] ?? [];
        if (! isset($session['accessJwt']) || isset($session['dpop_private_jwk'])) {
            return MessageSendResult::unsupported('Bluesky DMs require an app-password session with DM scope.');
        }

        $response = $this->client($session)->post('/xrpc/chat.bsky.convo.sendMessage', [
            'convoId' => $conversation->remote_conversation_id,
            'message' => ['text' => $text],
        ]);
        $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_SEND, $account, $response);

        if ($response->failed()) {
            return match ($response->status()) {
                401 => MessageSendResult::authExpired($this->excerpt($response)),
                429 => MessageSendResult::rateLimited($this->excerpt($response), RetryAfter::seconds($response)),
                default => MessageSendResult::failed($this->excerpt($response)),
            };
        }

        return MessageSendResult::ok((string) $response->json('id'));
    }

    /** @param array<string, mixed> $session */
    private function client(array $session): PendingRequest
    {
        $pds = (string) ($session['pds'] ?? self::DEFAULT_PDS);

        return $this->http->baseUrl($pds)
            ->withToken((string) $session['accessJwt'])
            ->withHeaders(['atproto-proxy' => self::PROXY])
            ->acceptJson();
    }

    private function mapFetchFailure(Response $response): ConversationFetchResult
    {
        return match ($response->status()) {
            401 => ConversationFetchResult::authExpired($this->excerpt($response)),
            429 => ConversationFetchResult::rateLimited($this->excerpt($response), RetryAfter::seconds($response)),
            default => ConversationFetchResult::failed($this->excerpt($response)),
        };
    }

    private function excerpt(Response $response): string
    {
        return Str::limit($response->body(), 300);
    }
}
