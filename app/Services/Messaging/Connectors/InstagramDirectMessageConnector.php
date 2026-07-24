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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Lists conversations and sends replies via the Instagram Messaging
 * (Graph API) Conversations endpoint, using the linked Page access token
 * stored on the connected account. Meta enforces a 24-hour reply window
 * from the counterpart's latest inbound message; sends outside that
 * window are declined locally without hitting the API.
 */
class InstagramDirectMessageConnector implements DirectMessageConnector
{
    use TracksUsage;

    private const string PLATFORM_PARAM = 'instagram';

    public function __construct(private readonly HttpFactory $http) {}

    private function apiVersion(): string
    {
        return (string) config('services.facebook.graph_version');
    }

    private function baseUrl(): string
    {
        return sprintf('https://graph.facebook.com/%s', $this->apiVersion());
    }

    /** @param array<string, mixed> $credentials */
    public function fetchConversations(ConnectedAccount $account, array $credentials, ?CarbonImmutable $since): ConversationFetchResult
    {
        $token = (string) ($credentials['access_token'] ?? '');
        $response = $this->http->acceptJson()->get($this->baseUrl()."/{$account->remote_account_id}/conversations", [
            'platform' => self::PLATFORM_PARAM,
            'fields' => 'participants,updated_time,messages{id,from,message,created_time}',
            'limit' => 50,
            'access_token' => $token,
        ]);

        $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_FETCH, $account, $response);

        if ($response->failed() || $response->json('error')) {
            return $this->mapFetchFailure($response);
        }

        $ourId = (string) $account->remote_account_id;
        $windowHours = (int) config('messages.meta_window_hours', 24);
        $conversations = [];

        foreach ($response->json('data', []) as $convo) {
            $conversations[] = $this->mapConversation($convo, $ourId, $windowHours);
        }

        return ConversationFetchResult::ok($conversations, data_get($response->json(), 'paging.cursors.after'));
    }

    /** @param array<string, mixed> $convo */
    private function mapConversation(array $convo, string $ourId, int $windowHours): FetchedConversation
    {
        $counterpart = collect($convo['participants']['data'] ?? [])
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
            counterpartHandle: isset($counterpart['username']) ? '@'.$counterpart['username'] : null,
            counterpartName: $counterpart['name'] ?? ($counterpart['username'] ?? null),
            counterpartAvatarUrl: null,
            counterpartRemoteId: $counterpart['id'] ?? null,
            messagingWindowExpiresAt: $latestInbound?->addHours($windowHours),
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
        $response = $this->http->acceptJson()->post($this->baseUrl()."/{$account->remote_account_id}/messages", [
            'recipient' => ['id' => $conversation->counterpart_remote_id],
            'message' => ['text' => $text],
            'access_token' => $token,
        ]);

        $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_SEND, $account, $response);

        if ($response->failed() || $response->json('error')) {
            return $this->mapSendFailure($response);
        }

        return MessageSendResult::ok((string) $response->json('message_id'));
    }

    private function mapFetchFailure(Response $response): ConversationFetchResult
    {
        $code = (int) $response->json('error.code', $response->status());

        return match (true) {
            in_array($code, [190, 401], true) => ConversationFetchResult::authExpired($this->excerpt($response)),
            in_array($code, [4, 17, 32, 613, 429], true) => ConversationFetchResult::rateLimited($this->excerpt($response), RetryAfter::seconds($response)),
            default => ConversationFetchResult::failed($this->excerpt($response)),
        };
    }

    private function mapSendFailure(Response $response): MessageSendResult
    {
        $code = (int) $response->json('error.code', $response->status());

        return match (true) {
            in_array($code, [190, 401], true) => MessageSendResult::authExpired($this->excerpt($response)),
            in_array($code, [4, 17, 32, 613, 429], true) => MessageSendResult::rateLimited($this->excerpt($response), RetryAfter::seconds($response)),
            default => MessageSendResult::failed($this->excerpt($response)),
        };
    }

    private function excerpt(Response $response): string
    {
        return Str::limit($response->body(), 300);
    }
}
