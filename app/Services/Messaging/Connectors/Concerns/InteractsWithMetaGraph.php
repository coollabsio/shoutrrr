<?php

declare(strict_types=1);

namespace App\Services\Messaging\Connectors\Concerns;

use App\Services\Engagement\RetryAfter;
use App\Services\Messaging\Data\ConversationFetchResult;
use App\Services\Messaging\Data\MessageSendResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Shared Meta Graph API helpers for the Instagram and Facebook direct
 * message connectors: versioned base URL, 24-hour messaging window
 * computation, and error-code mapping to the connector result types.
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
