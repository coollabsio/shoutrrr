<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Dto\Messaging\MessageSendResult;
use App\Enums\MessageDirection;
use App\Enums\SendStatus;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\PostMedia;
use App\Services\Publishing\TokenManager;
use Illuminate\Support\Str;

/**
 * Sends a reply into a conversation and records it locally. Shared by the
 * Classic inbox and the MCP `reply_to_conversation` tool.
 */
final readonly class ConversationReplier
{
    public function __construct(
        private MessageConnectorRegistry $registry,
        private TokenManager $tokens,
    ) {}

    /**
     * Returns the stored outbound message, or the failed send result.
     *
     * @param  list<string>  $mediaIds
     */
    public function reply(Conversation $conversation, string $text, array $mediaIds = []): DirectMessage|MessageSendResult
    {
        if (! $conversation->canReplyNow()) {
            return MessageSendResult::unsupported('The 24-hour reply window has closed.');
        }

        $media = $this->orderedMedia($conversation, $mediaIds);
        $account = $conversation->account;
        $credentials = $this->tokens->fresh($account);
        $result = $this->registry->for($conversation->platform)->sendMessage($account, $conversation, $text, $credentials, $media);

        if (! $result->isOk()) {
            if ($result->remoteMessageId !== null) {
                $this->persistPartialSend($conversation, $account, $media, $result);
            }

            return $result;
        }

        $row = DirectMessage::create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'remote_message_id' => $result->remoteMessageId ?? 'pending:'.Str::uuid(),
            'direction' => MessageDirection::Outbound,
            'author_remote_id' => $account->remote_account_id,
            'text' => $text,
            'attachments' => [],
            'remote_created_at' => now(),
            'is_ours' => true,
            'send_status' => SendStatus::Sent,
            'our_remote_id' => $result->remoteMessageId,
        ]);

        $this->claimMedia($row, $media);

        return $row;
    }

    /**
     * A Meta send can deliver the attachment and then fail on the text — the
     * remote thread already carries it, so this row (media claimed, text
     * empty since it never landed) keeps the local view honest and stops
     * `media:prune-uploads` from deleting an attachment the recipient can
     * already see, or the user from re-sending it as a duplicate.
     *
     * @param  list<PostMedia>  $media
     */
    private function persistPartialSend(Conversation $conversation, ConnectedAccount $account, array $media, MessageSendResult $result): void
    {
        $row = DirectMessage::create([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'remote_message_id' => $result->remoteMessageId,
            'direction' => MessageDirection::Outbound,
            'author_remote_id' => $account->remote_account_id,
            'text' => '',
            'attachments' => [],
            'remote_created_at' => now(),
            'is_ours' => true,
            'send_status' => SendStatus::Sent,
            'our_remote_id' => $result->remoteMessageId,
        ]);

        $this->claimMedia($row, $media);
    }

    /**
     * Hand the delivered attachments to the message that carried them.
     *
     * Until now they were orphaned uploads, which `media:prune-uploads` deletes
     * after six hours — and the bubble renders them for as long as the thread
     * exists. Claiming them also fixes their order for `DirectMessage::media()`.
     *
     * @param  list<PostMedia>  $media
     */
    private function claimMedia(DirectMessage $message, array $media): void
    {
        foreach ($media as $position => $item) {
            $item->forceFill([
                'direct_message_id' => $message->id,
                'position' => $position,
            ])->save();
        }

        $message->setRelation('media', collect($media));
    }

    /**
     * The picked attachments, resorted into the order the caller listed them —
     * `whereIn` returns database order.
     *
     * Only unclaimed orphan rows (no `post_id`, no `direct_message_id`) are
     * eligible: the ids are freshly uploaded DM attachments, so a claim must
     * never reach into media already owned by a post or another sent DM and
     * steal or reorder it.
     *
     * @param  list<string>  $ids
     * @return list<PostMedia>
     */
    private function orderedMedia(Conversation $conversation, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = PostMedia::query()
            ->where('workspace_id', $conversation->workspace_id)
            ->whereNull('post_id')
            ->whereNull('direct_message_id')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return array_values(array_filter(array_map(
            fn (string $id): ?PostMedia => $rows->get($id),
            $ids,
        )));
    }
}
