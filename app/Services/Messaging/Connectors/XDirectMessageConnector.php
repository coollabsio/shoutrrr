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

class XDirectMessageConnector implements DirectMessageConnector
{
    use TracksUsage;

    private const string BASE = 'https://api.twitter.com/2';

    public function __construct(private readonly HttpFactory $http) {}

    /** @param array<string, mixed> $credentials */
    public function fetchConversations(ConnectedAccount $account, array $credentials, ?CarbonImmutable $since): ConversationFetchResult
    {
        $query = [
            'dm_event.fields' => 'id,text,created_at,sender_id,dm_conversation_id,event_type',
            'expansions' => 'sender_id',
            'user.fields' => 'username,name,profile_image_url',
            'max_results' => 100,
        ];

        if ($since !== null) {
            $query['start_time'] = $since->toIso8601ZuluString();
        }

        $response = $this->http->withToken((string) ($credentials['access_token'] ?? ''))
            ->acceptJson()
            ->get(self::BASE.'/dm_events', $query);

        $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_FETCH, $account, $response);

        if ($response->failed()) {
            return $this->mapFetchFailure($response);
        }

        $users = collect($response->json('includes.users', []))->keyBy('id');
        $ourId = (string) $account->remote_account_id;

        $byConversation = [];
        foreach ((array) $response->json('data', []) as $event) {
            if (($event['event_type'] ?? null) !== 'MessageCreate') {
                continue;
            }

            $convoId = (string) ($event['dm_conversation_id'] ?? '');
            $senderId = (string) ($event['sender_id'] ?? '');
            $byConversation[$convoId][] = [
                'event' => $event,
                'inbound' => $senderId !== $ourId,
                'senderId' => $senderId,
            ];
        }

        $conversations = [];
        foreach ($byConversation as $convoId => $events) {
            $counterpart = collect($events)->firstWhere('inbound', true)['senderId'] ?? null;
            $user = $counterpart ? $users->get($counterpart) : null;

            $messages = array_map(function (array $e): FetchedMessage {
                return new FetchedMessage(
                    remoteMessageId: (string) $e['event']['id'],
                    direction: $e['inbound'] ? MessageDirection::Inbound : MessageDirection::Outbound,
                    authorRemoteId: $e['senderId'],
                    text: $e['event']['text'] ?? null,
                    attachments: [],
                    remoteCreatedAt: CarbonImmutable::parse($e['event']['created_at']),
                );
            }, $events);

            $conversations[] = new FetchedConversation(
                remoteConversationId: $convoId,
                counterpartHandle: isset($user['username']) ? '@'.$user['username'] : null,
                counterpartName: $user['name'] ?? null,
                counterpartAvatarUrl: $user['profile_image_url'] ?? null,
                counterpartRemoteId: $counterpart,
                messagingWindowExpiresAt: null,
                messages: $messages,
            );
        }

        return ConversationFetchResult::ok($conversations, $response->json('meta.next_token'));
    }

    /** @param array<string, mixed> $credentials */
    public function sendMessage(ConnectedAccount $account, Conversation $conversation, string $text, array $credentials): MessageSendResult
    {
        $response = $this->http->withToken((string) ($credentials['access_token'] ?? ''))
            ->acceptJson()
            ->post(self::BASE."/dm_conversations/{$conversation->remote_conversation_id}/messages", ['text' => $text]);

        $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_SEND, $account, $response);

        if ($response->failed()) {
            return match ($response->status()) {
                401 => MessageSendResult::authExpired($this->excerpt($response)),
                403 => MessageSendResult::unsupported($this->excerpt($response)),
                429 => MessageSendResult::rateLimited($this->excerpt($response), RetryAfter::seconds($response)),
                default => MessageSendResult::failed($this->excerpt($response)),
            };
        }

        return MessageSendResult::ok((string) $response->json('data.dm_event_id'));
    }

    private function mapFetchFailure(Response $response): ConversationFetchResult
    {
        return match ($response->status()) {
            401 => ConversationFetchResult::authExpired($this->excerpt($response)),
            403 => ConversationFetchResult::unsupported($this->excerpt($response)),
            429 => ConversationFetchResult::rateLimited($this->excerpt($response), RetryAfter::seconds($response)),
            default => ConversationFetchResult::failed($this->excerpt($response)),
        };
    }

    private function excerpt(Response $response): string
    {
        return Str::limit($response->body(), 300);
    }
}
