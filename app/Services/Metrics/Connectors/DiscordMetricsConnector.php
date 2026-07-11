<?php

declare(strict_types=1);

namespace App\Services\Metrics\Connectors;

use App\Dto\Metrics\AccountMetricsResult;
use App\Dto\Metrics\PostMetricsResult;
use App\Enums\UsageCategory;
use App\Models\ConnectedAccount;
use App\Models\PostTarget;
use App\Services\Metrics\Contracts\MetricsConnector;
use App\Services\Usage\Concerns\TracksUsage;
use App\Support\UsageOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Best-effort reaction metrics for Discord. A webhook can re-fetch the messages
 * it created (`GET {webhook}/messages/{id}`); the returned message object carries
 * a `reactions` array whose counts we sum into the `likes` column. Discord has no
 * like/repost split and a webhook cannot read channel- or server-level stats, so
 * comments/reposts/impressions are zero/null and account metrics are unsupported.
 */
class DiscordMetricsConnector implements MetricsConnector
{
    use TracksUsage;

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchPost(ConnectedAccount $account, PostTarget $target, array $credentials): PostMetricsResult
    {
        $webhookUrl = (string) ($credentials['webhook_url'] ?? '');
        $ids = $target->remote_ids ?? array_filter([$target->remote_id]);

        if ($webhookUrl === '' || $ids === []) {
            return PostMetricsResult::failed('Discord target has no webhook or message ids.');
        }

        $likes = 0;

        foreach ($ids as $id) {
            try {
                $response = $this->http
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->acceptJson()
                    ->get($webhookUrl.'/messages/'.$id);
            } catch (ConnectionException $e) {
                return PostMetricsResult::failed($e->getMessage());
            }

            $this->meter(UsageCategory::ExternalApi, UsageOperation::METRICS_FETCH_POST, $account, $response);

            if ($response->failed()) {
                return $response->status() === 429
                    ? PostMetricsResult::rateLimited($this->excerpt($response))
                    : PostMetricsResult::failed($this->excerpt($response));
            }

            /** @var list<array{count?: int}> $reactions */
            $reactions = $response->json('reactions', []);

            foreach ($reactions as $reaction) {
                $likes += (int) ($reaction['count'] ?? 0);
            }
        }

        return PostMetricsResult::ok($likes, 0, 0, raw: ['message_ids' => $ids]);
    }

    public function fetchAccount(ConnectedAccount $account, array $credentials): AccountMetricsResult
    {
        return AccountMetricsResult::unsupported('Discord webhooks cannot read server statistics.');
    }

    private function excerpt(Response $response): string
    {
        return (string) ($response->json('message') ?? mb_substr($response->body(), 0, 200));
    }
}
