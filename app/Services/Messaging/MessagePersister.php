<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Enums\MessageDirection;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Services\Messaging\Data\FetchedConversation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

class MessagePersister
{
    /**
     * @param  list<FetchedConversation>  $conversations
     * @return int number of newly inserted inbound messages
     */
    public function persist(ConnectedAccount $account, array $conversations): int
    {
        $insertedInbound = 0;

        foreach ($conversations as $fetched) {
            $conversation = Conversation::withoutGlobalScopes()->updateOrCreate(
                [
                    'connected_account_id' => $account->id,
                    'remote_conversation_id' => $fetched->remoteConversationId,
                ],
                [
                    'workspace_id' => $account->workspace_id,
                    'platform' => $account->platform,
                    'counterpart_handle' => $fetched->counterpartHandle,
                    'counterpart_name' => $fetched->counterpartName,
                    'counterpart_avatar_url' => $fetched->counterpartAvatarUrl,
                    'counterpart_remote_id' => $fetched->counterpartRemoteId,
                    'messaging_window_expires_at' => $fetched->messagingWindowExpiresAt,
                    'last_synced_at' => Date::now(),
                    'sync_cursor' => $fetched->cursor,
                ]
            );

            foreach ($fetched->messages as $message) {
                $row = DirectMessage::withoutGlobalScopes()->updateOrCreate(
                    [
                        'conversation_id' => $conversation->id,
                        'remote_message_id' => $message->remoteMessageId,
                    ],
                    [
                        'workspace_id' => $account->workspace_id,
                        'direction' => $message->direction,
                        'author_remote_id' => $message->authorRemoteId,
                        'text' => $message->text,
                        'attachments' => $message->attachments,
                        'remote_created_at' => $message->remoteCreatedAt,
                        'is_ours' => $message->direction === MessageDirection::Outbound,
                    ]
                );

                if ($row->wasRecentlyCreated && $message->direction === MessageDirection::Inbound) {
                    $insertedInbound++;
                }
            }

            $this->rollup($conversation);
        }

        return $insertedInbound;
    }

    private function rollup(Conversation $conversation): void
    {
        $latest = DirectMessage::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('remote_created_at')
            ->first();

        $unread = DirectMessage::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound->value)
            ->when($conversation->read_at !== null, fn ($q) => $q->where('remote_created_at', '>', $conversation->read_at))
            ->count();

        $conversation->forceFill([
            'last_message_at' => $latest?->remote_created_at,
            'last_message_preview' => $latest ? Str::limit((string) $latest->text, 140) : null,
            'unread_count' => $unread,
        ])->save();
    }
}
