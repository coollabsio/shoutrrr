<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Enums\SendStatus;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Services\Messaging\MessageConnectorRegistry;
use App\Services\Publishing\TokenManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class SendDirectMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public string $outgoingMessageId,
        public string $conversationId,
        public string $text,
        public Platform $platform,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited("messages-{$this->platform->value}")];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(MessageConnectorRegistry $registry, TokenManager $tokens): void
    {
        $outgoing = DirectMessage::withoutGlobalScopes()->whereKey($this->outgoingMessageId)->first();
        if ($outgoing === null || $outgoing->send_status === SendStatus::Sent) {
            return;
        }

        $conversation = Conversation::withoutGlobalScopes()->whereKey($this->conversationId)->first();
        if ($conversation === null) {
            $this->failRow($outgoing);

            return;
        }

        $account = $conversation->account()->withoutGlobalScopes()->first();
        $credentials = $tokens->fresh($account);

        $result = $registry->for($this->platform)->sendMessage($account, $conversation, $this->text, $credentials);

        if (! $result->isOk()) {
            $this->failRow($outgoing);

            return;
        }

        $outgoing->forceFill([
            'our_remote_id' => $result->remoteMessageId,
            'remote_message_id' => $result->remoteMessageId,
            'send_status' => SendStatus::Sent,
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        $outgoing = DirectMessage::withoutGlobalScopes()->whereKey($this->outgoingMessageId)->first();
        if ($outgoing !== null) {
            $this->failRow($outgoing);
        }
    }

    private function failRow(DirectMessage $outgoing): void
    {
        $outgoing->forceFill(['send_status' => SendStatus::Failed])->save();
    }
}
