<?php

declare(strict_types=1);

namespace App\Services\Messaging\Connectors\Concerns;

use App\Dto\Messaging\ConversationFetchResult;
use App\Dto\Messaging\MessageSendResult;
use App\Enums\Platform;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\Conversation;
use App\Models\PostMedia;
use App\Services\Media\ImageConversionFailed;
use App\Support\RetryAfter;
use App\Support\UsageOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Shared Meta Graph API helpers for the Instagram and Facebook direct
 * message connectors: versioned base URL, 24-hour messaging window
 * computation, the send flow (including attachments), and error-code
 * mapping to the connector result types.
 *
 * Using classes must expose an `$http` client, a `$publicMediaUrl` resolver,
 * and the TracksUsage trait.
 */
trait InteractsWithMetaGraph
{
    private function metaGraphBase(): string
    {
        return sprintf('https://graph.facebook.com/%s', (string) config('services.facebook.graph_version'));
    }

    private function metaWindowFrom(?CarbonImmutable $latestInbound): ?CarbonImmutable
    {
        return $latestInbound?->addHours((int) config('messages.meta_window_hours', 24));
    }

    /**
     * A Meta `message` carries either `text` or `attachment`, never both, so a
     * message with media becomes two POSTs — attachment first, so a failure
     * there delivers nothing. Returns the id of the last call that succeeded.
     *
     * @param  list<PostMedia>  $media  Capped upstream by Platform::maxDirectMessageMedia().
     * @param  array<string, mixed>  $basePayload  Extra top-level keys (Messenger's messaging_type).
     */
    private function sendMetaDirectMessage(
        ConnectedAccount $account,
        Conversation $conversation,
        string $text,
        string $token,
        array $media,
        Platform $platform,
        array $basePayload = [],
    ): MessageSendResult {
        /** @var list<array<string, mixed>> $messages */
        $messages = [];

        foreach ($media as $item) {
            try {
                $messages[] = ['attachment' => [
                    'type' => $item->isVideo() ? 'video' : 'image',
                    'payload' => ['url' => $this->metaAttachmentUrl($item, $platform)],
                ]];
            } catch (ImageConversionFailed $e) {
                return MessageSendResult::failed('Could not prepare the attachment for sending: '.$e->getMessage());
            }
        }

        if ($text !== '') {
            $messages[] = ['text' => $text];
        }

        if ($messages === []) {
            return MessageSendResult::failed('The message has neither text nor an attachment to send.');
        }

        $remoteMessageId = '';
        $delivered = 0;

        foreach ($messages as $message) {
            $response = $this->http->acceptJson()->post($this->metaGraphBase()."/{$account->remote_account_id}/messages", [
                ...$basePayload,
                'recipient' => ['id' => $conversation->counterpart_remote_id],
                'message' => $message,
                'access_token' => $token,
            ]);

            $this->meter(UsageCategory::ExternalApi, UsageOperation::DM_SEND, $account, $response);

            if ($response->failed() || $response->json('error')) {
                return $this->partialSendFailure($response, $delivered);
            }

            $delivered++;
            $remoteMessageId = (string) $response->json('message_id');
        }

        return MessageSendResult::ok($remoteMessageId);
    }

    /**
     * A send that failed partway is still a failure, but the user has to know
     * the earlier part landed — Meta cannot recall it, so retrying verbatim
     * delivers the attachment twice.
     */
    private function partialSendFailure(Response $response, int $delivered): MessageSendResult
    {
        $result = $this->mapMetaSendFailure($response);

        if ($delivered === 0) {
            return $result;
        }

        return $result->withExcerpt(
            'The attachment was delivered but the message text could not be sent, so retrying will send the attachment again. '.($result->excerpt ?? '')
        );
    }

    /**
     * Meta fetches this URL server-side, so it must be absolute and public.
     * A GIF passes no platform: the JPEG safety-net would flatten the animation.
     *
     * @throws ImageConversionFailed
     */
    private function metaAttachmentUrl(PostMedia $media, Platform $platform): string
    {
        return $this->publicMediaUrl->for($media, $media->mime === 'image/gif' ? null : $platform);
    }

    private function mapMetaFetchFailure(Response $response): ConversationFetchResult
    {
        $code = (int) $response->json('error.code', $response->status());

        return match (true) {
            in_array($code, [190, 401], true) => ConversationFetchResult::authExpired($this->excerpt($response)),
            in_array($code, [4, 17, 32, 613, 429], true) => ConversationFetchResult::rateLimited($this->excerpt($response), RetryAfter::seconds($response)),
            default => ConversationFetchResult::failed($this->excerpt($response)),
        };
    }

    private function mapMetaSendFailure(Response $response): MessageSendResult
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
