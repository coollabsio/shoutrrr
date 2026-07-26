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
use Illuminate\Support\Collection;
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

        /** @var array<int, array<string, mixed>> $usersData */
        $usersData = (array) $response->json('includes.users', []);
        $users = collect($usersData)->keyBy('id');
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

        // Resolve who each conversation is with before mapping, so a batched
        // user lookup can fill in anyone the expansion didn't cover.
        $counterparts = [];
        foreach ($byConversation as $convoId => $events) {
            $counterparts[$convoId] = collect($events)->firstWhere('inbound', true)['senderId']
                ?? $this->counterpartFromConversationId((string) $convoId, $ourId);
        }

        $users = $this->hydrateMissingUsers($users, $counterparts, $account, $credentials);

        $conversations = [];
        foreach ($byConversation as $convoId => $events) {
            $counterpart = $counterparts[$convoId] ?? null;
            $user = $counterpart !== null ? $users->get($counterpart) : null;

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

    /**
     * X names a 1:1 DM conversation `{userA_id}-{userB_id}`, so the person we
     * are talking to is recoverable even when the fetch window contains only
     * our own outbound messages (no inbound sender to read it off). Group
     * conversations don't follow the two-id shape and resolve to null.
     */
    private function counterpartFromConversationId(string $conversationId, string $ourId): ?string
    {
        $parts = explode('-', $conversationId);

        if (count($parts) !== 2 || ! in_array($ourId, $parts, true)) {
            return null;
        }

        $counterpart = $parts[0] === $ourId ? $parts[1] : $parts[0];

        return $counterpart === '' ? null : $counterpart;
    }

    /**
     * `expansions=sender_id` only describes users who sent something in the
     * window, so counterparts recovered from a conversation id have no profile
     * attached and would render as a nameless row. Look those up in one extra
     * call rather than per conversation.
     *
     * @param  Collection<string, array<string, mixed>>  $users
     * @param  array<string, string|null>  $counterparts
     * @param  array<string, mixed>  $credentials
     * @return Collection<string, array<string, mixed>>
     */
    private function hydrateMissingUsers(Collection $users, array $counterparts, ConnectedAccount $account, array $credentials): Collection
    {
        $missing = collect($counterparts)
            ->filter(fn (?string $id): bool => $id !== null && ! $users->has($id))
            ->unique()
            ->values()
            ->take(100); // X caps a users lookup at 100 ids.

        if ($missing->isEmpty()) {
            return $users;
        }

        $response = $this->http->withToken((string) ($credentials['access_token'] ?? ''))
            ->acceptJson()
            ->get(self::BASE.'/users', [
                'ids' => $missing->implode(','),
                'user.fields' => 'username,name,profile_image_url',
            ]);

        $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_FETCH, $account, $response);

        // A failed lookup is cosmetic only — the conversation still syncs, it
        // just shows without a display name until the next fetch.
        if ($response->failed()) {
            return $users;
        }

        /** @var array<int, array<string, mixed>> $found */
        $found = (array) $response->json('data', []);

        // Not merge(): X ids are numeric strings, so PHP stores them as integer
        // keys and array_merge() would renumber them, detaching every profile
        // from its id. put() keeps the key.
        foreach ($found as $user) {
            if (isset($user['id'])) {
                $users->put((string) $user['id'], $user);
            }
        }

        return $users;
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
